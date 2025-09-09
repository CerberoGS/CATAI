<?php
declare(strict_types=1);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

function send_json($arr, $code=200){
  http_response_code($code);
  $payload = json_encode($arr, JSON_UNESCAPED_SLASHES);
  while (ob_get_level()) { ob_end_clean(); }
  echo $payload;
  exit;
}

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    send_json(['ok'=>false,'error'=>'php_fatal','detail'=>$e['message'].' @ '.$e['file'].':'.$e['line']], 200);
  }
});
set_error_handler(function ($sev, $msg, $file, $line) {
  send_json(['ok'=>false,'error'=>'php_runtime','detail'=>"$msg @ $file:$line"], 200);
});

function http_get_json(string $url, int $timeout = 25): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_HTTPHEADER => ['Accept: application/json']
  ]);
  $body = curl_exec($ch);
  if ($body === false) { $err = curl_error($ch); curl_close($ch); throw new Exception("curl_error: $err"); }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $trim = ltrim((string)$body);
  if ($trim !== '' && str_starts_with($trim, '<')) throw new Exception("non_json_html prefix=" . substr($trim,0,120));
  $json = ($trim === '') ? [] : json_decode($trim, true);
  if ($json === null && $trim !== '') throw new Exception("non_json prefix=" . substr($trim,0,120));
  if ($code < 200 || $code >= 300) throw new Exception("http_status_$code payload_prefix=" . substr($trim,0,120));
  return $json ?? [];
}

// Normaliza resoluciones (1h/60m → 60min, etc.)
function norm_reso(string $r): string {
  $x = strtolower(trim($r));
  $x = str_replace([' ', 'min.', 'mins'], ['','min','min'], $x);
  return match ($x) {
    '1h','1hr','1hour','60','60m','60min' => '60min',
    '30','30m','30min'                    => '30min',
    '15','15m','15min'                    => '15min',
    '5','5m','5min'                       => '5min',
    '1','1m','1min'                       => '1min',
    'd','1d','day','daily'                => 'daily',
    'w','1w','week','weekly'              => 'weekly',
    default                               => $x
  };
}

$input = json_decode(file_get_contents('php://input') ?: '{}', true);
$symbol      = strtoupper(trim($input['symbol'] ?? ''));
$resolutions = $input['resolutions'] ?? [];
$indicators  = $input['indicators'] ?? [];
$provider    = strtolower(trim($input['provider'] ?? 'auto'));

if ($symbol === '' || !is_array($resolutions) || !count($resolutions)) {
  send_json(['ok'=>false,'error'=>'missing_params']);
}

// Claves por usuario (con fallback a config/env via helpers)
try {
  $u = require_user();
  $uid = (int)($u['id'] ?? 0);
} catch (Throwable $e) {
  $uid = 0;
}
if (!function_exists('get_api_key_for') || !function_exists('user_net_timeout_ms') || !function_exists('user_net_retries')) require_once __DIR__ . '/helpers.php';
$__TIINGO = get_api_key_for($uid, 'tiingo', 'TIINGO_API_KEY');
$__AV     = get_api_key_for($uid, 'alphavantage', 'ALPHAVANTAGE_API_KEY');
if (!defined('TIINGO_API_KEY')) define('TIINGO_API_KEY', $__TIINGO);
if (!defined('ALPHA_VANTAGE_API_KEY')) define('ALPHA_VANTAGE_API_KEY', $__AV);

// Timeouts/retries por proveedor desde settings.data.net.* (ms -> s)
$__NET_TIMEOUTS = ['tiingo' => 25, 'alphavantage' => 25];
$__NET_RETRIES  = ['tiingo' => 0,  'alphavantage' => 0];
if ($uid > 0 && function_exists('user_net_timeout_ms')) {
  $msTi = (int)user_net_timeout_ms($uid, 'tiingo', 25000);
  $__NET_TIMEOUTS['tiingo'] = max(3, (int)ceil($msTi / 1000));
  $msAv = (int)user_net_timeout_ms($uid, 'alphavantage', 25000);
  $__NET_TIMEOUTS['alphavantage'] = max(3, (int)ceil($msAv / 1000));
}
if ($uid > 0 && function_exists('user_net_retries')) {
  $__NET_RETRIES['tiingo']       = max(0, (int)user_net_retries($uid, 'tiingo', 0));
  $__NET_RETRIES['alphavantage'] = max(0, (int)user_net_retries($uid, 'alphavantage', 0));
}
$GLOBALS['__NET_TIMEOUTS'] = $__NET_TIMEOUTS;
$GLOBALS['__NET_RETRIES']  = $__NET_RETRIES;
function net_timeout_for(string $prov): int { $map = $GLOBALS['__NET_TIMEOUTS'] ?? []; return (int)($map[$prov] ?? 25); }
function net_retries_for(string $prov): int { $map = $GLOBALS['__NET_RETRIES']  ?? []; return (int)($map[$prov] ?? 0); }

// GET con reintentos locales (manteniendo validaciones de http_get_json)
function http_get_json_retry(string $url, int $timeoutSec, int $retries): array {
  $attempts = max(1, $retries + 1);
  for ($i = 1; $i <= $attempts; $i++) {
    try {
      return http_get_json($url, $timeoutSec);
    } catch (Exception $e) {
      if ($i === $attempts) throw $e;
      usleep(min((int)(100000 * (2 ** ($i - 1))), 800000));
    }
  }
  return [];
}

// Indicadores
function ema(array $data, int $period): array {
  $k = 2 / ($period + 1); $out = []; $prev = null;
  foreach ($data as $p) { if ($p===null){$out[]=null;continue;} $prev = $prev===null ? $p : ($p*$k + $prev*(1-$k)); $out[]=$prev; }
  return $out;
}
function sma(array $data, int $period): array {
  $out=[]; $sum=0; $q=[];
  foreach ($data as $p) { $q[]=$p; $sum+=$p; if(count($q)>$period)$sum-=array_shift($q); $out[]=count($q)===$period?$sum/$period:null; }
  return $out;
}
function rsi14(array $close): array {
  $n=14; $rsi=[]; $prev=null; $avgG=null; $avgL=null; $i=0; $g=[]; $l=[];
  foreach ($close as $p) {
    if ($prev===null){$rsi[]=50; $prev=$p; continue;}
    $chg=$p-$prev; $prev=$p; $gain=max(0,$chg); $loss=max(0,-$chg);
    if ($i<$n){ $g[]=$gain; $l[]=$loss; $i++; if($i===$n){$avgG=array_sum($g)/$n; $avgL=array_sum($l)/$n;} $rsi[]=50; continue; }
    $avgG = ($avgG*($n-1)+$gain)/$n; $avgL = ($avgL*($n-1)+$loss)/$n; $rs=$avgL==0?INF:$avgG/$avgL; $rsi[] = 100-(100/(1+$rs));
  } return $rsi;
}

// Fetchers
function fetch_tiingo(string $symbol, string $reso): ?array {
  if (!TIINGO_API_KEY) return null;
  $tSec = net_timeout_for('tiingo');
  $rTry = net_retries_for('tiingo');
  $interval = match($reso) {
    '1min'  => '1min',
    '5min'  => '5min',
    '15min' => '15min',
    '30min' => '15min',
    '60min' => '60min',
    'daily' => 'daily',
    'weekly'=> 'weekly',
    default => '5min'
  };

  if (in_array($interval, ['daily','weekly'], true)) {
    $url = 'https://api.tiingo.com/tiingo/daily/' . urlencode($symbol) . '/prices?token=' . urlencode(TIINGO_API_KEY);
    $j = http_get_json_retry($url, $tSec, $rTry);
    $data=[]; foreach ($j as $row){ $data[]=['t'=>strtotime(substr($row['date']??'',0,10).' 16:00:00'),'c'=>(float)($row['close']??0)]; }
    return $data ?: null;
  } else {
    $url = 'https://api.tiingo.com/iex/' . urlencode($symbol) . '/prices?token=' . urlencode(TIINGO_API_KEY)
         . '&resampleFreq=' . urlencode($interval) . '&columns=date,close';
    $j = http_get_json_retry($url, $tSec, $rTry);
    $data=[]; foreach ($j as $row){ $t=isset($row['date'])?strtotime($row['date']):null; $c=isset($row['close'])?(float)$row['close']:null; if($t && $c!==null)$data[]=['t'=>$t,'c'=>$c]; }
    return $data ?: null;
  }
}
function fetch_av(string $symbol, string $reso): ?array {
  if (!ALPHA_VANTAGE_API_KEY) return null;
  $tSec = net_timeout_for('alphavantage');
  $rTry = net_retries_for('alphavantage');

  if (in_array($reso, ['1min','5min','15min','30min','60min'], true)) {
    $interval = $reso;
    $url = 'https://www.alphavantage.co/query?function=TIME_SERIES_INTRADAY'
         . '&symbol=' . urlencode($symbol)
         . '&interval=' . urlencode($interval)
         . '&outputsize=compact'
         . '&apikey=' . urlencode(ALPHA_VANTAGE_API_KEY);
    $j = http_get_json_retry($url, $tSec, $rTry);
    $key = "Time Series ($interval)"; $ts = $j[$key] ?? null; if(!is_array($ts)) return null;
    ksort($ts);
    $data=[]; foreach ($ts as $tsStr=>$row){ $t=strtotime($tsStr); $c=isset($row['4. close'])?(float)$row['4. close']:null; if($t && $c!==null)$data[]=['t'=>$t,'c'=>$c]; }
    return $data ?: null;
  }

  if ($reso === 'daily') {
    $url = 'https://www.alphavantage.co/query?function=TIME_SERIES_DAILY'
         . '&symbol=' . urlencode($symbol)
         . '&outputsize=compact'
         . '&apikey=' . urlencode(ALPHA_VANTAGE_API_KEY);
    $j = http_get_json_retry($url, $tSec, $rTry);
    $ts = $j['Time Series (Daily)'] ?? null; if(!is_array($ts)) return null; ksort($ts);
    $data=[]; foreach ($ts as $tsStr=>$row){ $t=strtotime($tsStr); $c=isset($row['4. close'])?(float)$row['4. close']:null; if($t && $c!==null)$data[]=['t'=>$t,'c'=>$c]; }
    return $data ?: null;
  }
  return null;
}

function build_indicators(array $rows, array $want): array {
  $close = array_map(fn($r)=>$r['c'], $rows);
  $last  = ['price'=>end($close)];
  $series=[];

  if (!empty($want['sma20'])) { $s=sma($close,20);  $series['sma20']=$s;  $last['sma20']=end($s); }
  if (!empty($want['ema20'])) { $s=ema($close,20);  $series['ema20']=$s;  $last['ema20']=end($s); }
  if (!empty($want['ema40'])) { $s=ema($close,40);  $series['ema40']=$s;  $last['ema40']=end($s); }
  if (!empty($want['ema100'])){ $s=ema($close,100); $series['ema100']=$s; $last['ema100']=end($s); }
  if (!empty($want['ema200'])){ $s=ema($close,200); $series['ema200']=$s; $last['ema200']=end($s); }
  if (!empty($want['rsi14'])) { $s=rsi14($close);    $series['rsi14']=$s;  $last['rsi14']=end($s); }

  return ['closingPricesCount'=>count($close),'indicators'=>['last'=>$last,'series'=>$series]];
}

$seriesByRes = [];
foreach ($resolutions as $r) {
  $reso = norm_reso((string)$r);
  $want = $indicators[$r] ?? $indicators[$reso] ?? [];

  try {
    $order = ($provider === 'tiingo') ? ['tiingo','av'] : (($provider === 'av') ? ['av','tiingo'] : ['tiingo','av']);
    $data = null; $used=null;

    foreach ($order as $p) {
      if ($p==='tiingo') $data = fetch_tiingo($symbol, $reso);
      if ($p==='av' && $data===null) $data = fetch_av($symbol, $reso);
      if ($data!==null) { $used=$p; break; }
    }
    if ($data===null && $reso==='60min') { $data = fetch_av($symbol,'60min'); if($data!==null)$used='av'; }

    if ($data===null) { $seriesByRes[$r] = ['error'=>'no_data_from_provider']; continue; }
    $seriesByRes[$r] = array_merge(['provider'=>$used ?? 'unknown'], build_indicators($data, $want));
  } catch (Exception $e) {
    $seriesByRes[$r] = ['error'=>$e->getMessage()];
  }
}

send_json(['symbol'=>$symbol,'seriesByRes'=>$seriesByRes]);
