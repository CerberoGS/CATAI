<?php
// /bolsa/api/time_series_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/quota.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * GET JSON con User-Agent y Accept forzados.
 */
function http_get_json_with_ua(string $url, array $headers = [], int $timeout = 35, ?int &$http_code = null, ?string &$raw = null): ?array {
  $ua = 'Mozilla/5.0 (compatible; CATAI/1.0; +https://cerberogrowthsolutions.com)';
  $h = array_values(array_filter(array_merge([
    'Accept: application/json',
    'User-Agent: ' . $ua,
  ], $headers), fn($x) => is_string($x) && strlen($x)));

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $h,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    $http_code = 0;
    curl_close($ch);
    throw new Exception('CURL error: ' . $err);
  }
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
  curl_close($ch);

  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

/** Indicadores rápidos */
$sma = function(array $arr, int $n): ?float {
  if (count($arr) < $n) return null;
  $sum = 0.0; for ($i=0; $i<$n; $i++) $sum += (float)$arr[$i];
  return $sum / $n;
};
$ema = function(array $arr, int $n) use ($sma): ?float {
  if (count($arr) < $n) return null;
  $k = 2.0 / ($n + 1.0);
  $series = array_reverse($arr); // ascendente
  $seed = $sma(array_slice($series, 0, $n), $n);
  if ($seed === null) return null;
  $emaVal = $seed;
  for ($i = $n; $i < count($series); $i++) {
    $price = (float)$series[$i];
    $emaVal = ($price - $emaVal) * $k + $emaVal;
  }
  return $emaVal;
};
$rsi14 = function(array $arr): ?float {
  $n = 14; if (count($arr) < $n + 1) return null;
  $g=0.0; $l=0.0;
  for ($i=count($arr)-1; $i>count($arr)-1-$n; $i--) {
    $chg = (float)$arr[$i] - (float)$arr[$i-1];
    if ($chg>0) $g += $chg; else $l += -$chg;
  }
  $avgG = $g/$n; $avgL = $l/$n;
  for ($i=count($arr)-1-$n; $i>0; $i--) {
    $chg = (float)$arr[$i] - (float)$arr[$i-1];
    $gain = $chg>0 ? $chg : 0.0; $loss = $chg<0 ? -$chg : 0.0;
    $avgG = ($avgG*($n-1)+$gain)/$n; $avgL = ($avgL*($n-1)+$loss)/$n;
  }
  if ($avgL==0.0) return 100.0;
  $rs = $avgG/$avgL;
  return 100.0 - (100.0/(1.0+$rs));
};

/** Normalizador de resoluciones */
$normRes = function(string $r): string {
  $s = strtolower(trim($r));
  $s = preg_replace('/\s+/', '', $s);
  if (in_array($s, ['daily','1d','day','1day','diario'], true)) return 'daily';
  if (in_array($s, ['weekly','1w','week','1week','semanal'], true)) return 'weekly';
  if (in_array($s, ['1h','h','1hour','hour'], true)) return '60min';
  if (preg_match('/^(\d+)(m|min|mins|minute|minutes)$/', $s, $m)) {
    $n = (int)$m[1]; if (in_array($n, [1,5,15,30,60], true)) return $n.'min';
  }
  if (preg_match('/^(1|5|15|30|60)min$/', $s)) return $s;
  return $s;
};

/** Aggregator semanal desde diarios (Tiingo) */
$aggregate_weekly_from_tiingo_daily = function(array $rows): array {
  // $rows: como devuelve Tiingo daily prices (array de objetos con 'date', 'adjClose'/'close')
  // Devolvemos lista de cierres semanales (último de cada semana ISO).
  $buckets = []; // key = ISO year-week (oW), value = last close
  foreach ($rows as $r) {
    $dateStr = $r['date'] ?? null;
    if (!$dateStr) continue;
    try {
      $dt = new DateTime($dateStr);
    } catch (Throwable $e) {
      continue;
    }
    $key = $dt->format('oW'); // ISO week-year + week
    $close = isset($r['adjClose']) ? (float)$r['adjClose'] : (float)($r['close'] ?? 0);
    $buckets[$key] = $close; // el último que aparezca para esa semana
  }
  // mantener orden cronológico por key
  ksort($buckets);
  return array_values($buckets);
};

/** Fetchers */
$fetchAV = function(string $sym, string $reso, string $key) use ($sma) {
  if ($key==='') throw new Exception('ALPHAVANTAGE_API_KEY vacío');
  $code=0; $raw=null; $j=null;

  if (in_array($reso, ['1min','5min','15min','30min','60min'], true)) {
    $interval = $reso;
    $url = "https://www.alphavantage.co/query?function=TIME_SERIES_INTRADAY&symbol="
          .rawurlencode($sym)."&interval=".$interval."&outputsize=full&datatype=json&apikey="
          .rawurlencode($key);
    $j = http_get_json_with_ua($url, [], 35, $code, $raw);
    if ($code===0) throw new Exception("AV intraday $interval: HTTP 0 (red/timeout)");
    if ($code<200 || $code>=300) throw new Exception("AV intraday $interval: ".($raw ? substr($raw,0,180) : "HTTP $code"));
    $keyTs = "Time Series ($interval)";
    $ts = $j[$keyTs] ?? null;
    if (!is_array($ts)) {
      $note = $j['Note'] ?? $j['Information'] ?? 'respuesta inesperada';
      throw new Exception("AV intraday $interval: $note");
    }
    krsort($ts);
    $closes = [];
    foreach ($ts as $row) { $closes[] = (float)($row['4. close'] ?? $row['4. Close'] ?? $row['close'] ?? 0); }
    return $closes;
  }

  if ($reso==='daily') {
    $url = "https://www.alphavantage.co/query?function=TIME_SERIES_DAILY_ADJUSTED&symbol="
          .rawurlencode($sym)."&outputsize=full&datatype=json&apikey=".rawurlencode($key);
    $j = http_get_json_with_ua($url, [], 35, $code, $raw);
    if ($code===0) throw new Exception("AV daily: HTTP 0 (red/timeout)");
    if ($code<200 || $code>=300) throw new Exception("AV daily: ".($raw ? substr($raw,0,180) : "HTTP $code"));
    $ts = $j['Time Series (Daily)'] ?? null;
    if (!is_array($ts)) { $note = $j['Note'] ?? $j['Information'] ?? 'respuesta inesperada'; throw new Exception("AV daily: $note"); }
    krsort($ts);
    $closes = [];
    foreach ($ts as $row) { $closes[] = (float)($row['4. close'] ?? $row['4. Close'] ?? $row['close'] ?? 0); }
    return $closes;
  }

  if ($reso==='weekly') {
    $url = "https://www.alphavantage.co/query?function=WEEKLY_ADJUSTED&symbol="
          .rawurlencode($sym)."&datatype=json&apikey=".rawurlencode($key);
    $j = http_get_json_with_ua($url, [], 35, $code, $raw);
    if ($code===0) throw new Exception("AV weekly: HTTP 0 (red/timeout)");
    if ($code<200 || $code>=300) throw new Exception("AV weekly: ".($raw ? substr($raw,0,180) : "HTTP $code"));
    $ts = $j['Weekly Adjusted Time Series'] ?? null;
    if (!is_array($ts)) { $note = $j['Note'] ?? $j['Information'] ?? 'respuesta inesperada'; throw new Exception("AV weekly: $note"); }
    krsort($ts);
    $closes = [];
    foreach ($ts as $row) { $closes[] = (float)($row['4. close'] ?? $row['4. Close'] ?? $row['close'] ?? 0); }
    return $closes;
  }

  throw new Exception("Resolución no soportada: $reso");
};

$fetchFH = function(string $sym, string $reso, string $key) {
  if ($key==='') throw new Exception('FINNHUB_API_KEY vacío');
  $map = ['1min'=>'1','5min'=>'5','15min'=>'15','30min'=>'30','60min'=>'60','daily'=>'D','weekly'=>'W'];
  if (!isset($map[$reso])) throw new Exception("Resolución no soportada: $reso");
  $fhRes = $map[$reso];

  $now = time();
  if (in_array($reso, ['1min','5min','15min','30min','60min'], true)) $from = $now - 90*24*3600;
  elseif ($reso==='daily') $from = $now - 3*365*24*3600;
  else $from = $now - 8*365*24*3600;

  $url = "https://finnhub.io/api/v1/stock/candle?symbol=".rawurlencode($sym).
         "&resolution=$fhRes&from=$from&to=$now&token=".rawurlencode($key);

  $code=0; $raw=null;
  $j = http_get_json_with_ua($url, [], 35, $code, $raw);
  if ($code===0) throw new Exception("Finnhub candle: HTTP 0 (red/timeout)");
  if ($code<200 || $code>=300) throw new Exception("Finnhub candle: HTTP $code ".substr((string)$raw,0,180));
  if (($j['s'] ?? '') !== 'ok' || empty($j['c']) || !is_array($j['c'])) {
    $status = $j['s'] ?? 'sin_estado';
    throw new Exception("Finnhub candle: estado=$status");
  }
  $closes = array_reverse(array_map('floatval', $j['c']));
  return $closes;
};

$fetchTiingo = function(string $sym, string $reso, string $key) use ($aggregate_weekly_from_tiingo_daily) {
  if ($key==='') throw new Exception('TIINGO_API_KEY vacío');

  // Intradía en Tiingo requiere suscripción IEX.
  if (in_array($reso, ['1min','5min','15min','30min','60min'], true)) {
    $map = ['1min'=>'1min','5min'=>'5min','15min'=>'15min','30min'=>'30min','60min'=>'60min'];
    $rf = $map[$reso];
    // NOTA: intradía es endpoint IEX
    $start = (new DateTime('-30 days'))->format('Y-m-d');
    $url = "https://api.tiingo.com/iex/".rawurlencode($sym)."/prices?startDate=$start&resampleFreq=$rf&columns=date,close&token="
          .rawurlencode($key);
    $code=0; $raw=null;
    $j = http_get_json_with_ua($url, [], 35, $code, $raw);
    if ($code===401 || $code===403) {
      throw new Exception("Tiingo intraday ($rf): requiere suscripción IEX o permisos. HTTP $code ".substr((string)$raw,0,180));
    }
    if ($code<200 || $code>=300) throw new Exception("Tiingo intraday ($rf): HTTP $code ".substr((string)$raw,0,180));
    if (!is_array($j) || !count($j)) throw new Exception("Tiingo intraday ($rf): respuesta vacía");
    $closes = [];
    // Tiingo IEX devuelve array con 'date' y 'close'
    // (ya vienen ordenados ascendente normalmente; invertimos para tener reciente->antiguo)
    foreach ($j as $row) { $closes[] = (float)($row['close'] ?? 0); }
    $closes = array_reverse($closes);
    return $closes;
  }

  // Daily (free en la mayoría de los casos)
  if ($reso==='daily' || $reso==='weekly') {
    $start = (new DateTime('-8 years'))->format('Y-m-d');
    $url = "https://api.tiingo.com/tiingo/daily/".rawurlencode($sym)."/prices?startDate=$start&token=".rawurlencode($key);
    $code=0; $raw=null;
    $j = http_get_json_with_ua($url, [], 35, $code, $raw);
    if ($code<200 || $code>=300) throw new Exception("Tiingo daily: HTTP $code ".substr((string)$raw,0,180));
    if (!is_array($j) || !count($j)) throw new Exception("Tiingo daily: respuesta vacía");
    // Convertimos a closes (más reciente primero)
    $clDailyAsc = [];
    foreach ($j as $row) {
      $clDailyAsc[] = isset($row['adjClose']) ? (float)$row['adjClose'] : (float)($row['close'] ?? 0);
    }
    // $j suele venir cronológicamente ascendente → hacemos reciente->antiguo
    $clDaily = array_reverse($clDailyAsc);

    if ($reso==='daily') return $clDaily;

    // Weekly: agregamos
    // Para calcular indicadores semanales, necesitamos cierres semanales cronológicos.
    // Usamos los datos originales con fechas para agrupar:
    $weekly = $aggregate_weekly_from_tiingo_daily($j);
    $weekly = array_reverse($weekly); // reciente->antiguo
    if (!count($weekly)) throw new Exception("Tiingo weekly: no se pudo agregar desde diarios");
    return $weekly;
  }

  throw new Exception("Resolución no soportada: $reso");
};

try {
  $user = require_user();
  quota_check_and_log($user['id'], 'time_series', 1);

  // Entrada
  $bodyRaw = file_get_contents('php://input');
  $body = [];
  if ($bodyRaw) { $tmp = json_decode($bodyRaw, true); if (is_array($tmp)) $body = $tmp; }

  $symbol  = strtoupper(trim($body['symbol'] ?? ($_GET['symbol'] ?? 'TSLA')));
  $provIn  = strtolower(trim($body['provider'] ?? ($_GET['provider'] ?? 'auto')));
  $debug   = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;

  $resIn = $body['resolutions'] ?? ($_GET['resolutions'] ?? null);
  if (is_string($resIn)) {
    $resolutions = array_filter(array_map('trim', explode(',', $resIn)));
  } elseif (is_array($resIn)) {
    $resolutions = $resIn;
  } else {
    $resolutions = ['daily','weekly']; // por defecto más seguros
  }
  $resolutions = array_values(array_unique(array_map($normRes, $resolutions)));

  if ($symbol === '') json_out(['error'=>'symbol_required'], 400);

  // Claves por usuario (con fallback a config/env)
  $uid  = (int)($user['id'] ?? 0);
  $avKey = get_api_key_for($uid, 'alphavantage', 'ALPHAVANTAGE_API_KEY');
  $fhKey = get_api_key_for($uid, 'finnhub',      'FINNHUB_API_KEY');
  $tgKey = get_api_key_for($uid, 'tiingo',       'TIINGO_API_KEY');

  $seriesByRes = [];
  foreach ($resolutions as $reso) {
    try {
      $closes = null; $used = null;

      if ($provIn === 'finnhub') {
        $closes = $fetchFH($symbol, $reso, $fhKey); $used = 'finnhub';
      } elseif ($provIn === 'alphavantage' || $provIn === 'av') {
        $closes = $fetchAV($symbol, $reso, $avKey); $used = 'alphavantage';
      } elseif ($provIn === 'tiingo') {
        $closes = $fetchTiingo($symbol, $reso, $tgKey); $used = 'tiingo';
      } else {
        // AUTO: Finnhub → AlphaVantage → Tiingo (daily/weekly seguro)
        try { $closes = $fetchFH($symbol, $reso, $fhKey); $used = 'finnhub'; }
        catch (Throwable $e1) {
          try { $closes = $fetchAV($symbol, $reso, $avKey); $used = 'alphavantage'; }
          catch (Throwable $e2) {
            try { $closes = $fetchTiingo($symbol, $reso, $tgKey); $used = 'tiingo'; }
            catch (Throwable $e3) {
              throw new Exception("AUTO falló (FH→AV→TG). FH: ".$e1->getMessage()." | AV: ".$e2->getMessage()." | TG: ".$e3->getMessage());
            }
          }
        }
      }

      $last = [
        'price'  => isset($closes[0]) ? (float)$closes[0] : null,
        'rsi14'  => $rsi14($closes),
        'sma20'  => $sma($closes, 20),
        'ema20'  => $ema($closes, 20),
        'ema40'  => $ema($closes, 40),
        'ema100' => $ema($closes, 100),
        'ema200' => $ema($closes, 200),
      ];

      // Fallback: si intradía quedó sin datos, intenta diario con cualquier proveedor disponible
      if ((!is_array($closes) || count($closes) === 0) && in_array($reso, ['1min','5min','15min','30min','60min'], true)) {
        $fbProvider = null; $fbCloses = null;
        try { $fbCloses = $fetchFH($symbol, 'daily', $fhKey); $fbProvider = 'finnhub'; }
        catch (Throwable $e1) { try { $fbCloses = $fetchAV($symbol, 'daily', $avKey); $fbProvider = 'alphavantage'; }
          catch (Throwable $e2) { try { $fbCloses = $fetchTiingo($symbol, 'daily', $tgKey); $fbProvider = 'tiingo'; } catch (Throwable $e3) { /* sin fallback */ } }
        }
        if (is_array($fbCloses) && count($fbCloses)) {
          $last = [
            'price'  => isset($fbCloses[0]) ? (float)$fbCloses[0] : null,
            'rsi14'  => $rsi14($fbCloses),
            'sma20'  => $sma($fbCloses, 20),
            'ema20'  => $ema($fbCloses, 20),
            'ema40'  => $ema($fbCloses, 40),
            'ema100' => $ema($fbCloses, 100),
            'ema200' => $ema($fbCloses, 200),
          ];
          $seriesByRes[$reso] = [
            'provider'   => $fbProvider ?? ($used ?? $provIn),
            'indicators' => ['last' => $last],
            'fallback'   => ['from' => $reso, 'to' => 'daily', 'provider' => $fbProvider],
          ];
          continue;
        }
      }

      $seriesByRes[$reso] = [
        'provider'   => $used ?? $provIn,
        'indicators' => ['last' => $last],
      ];
    } catch (Throwable $e) {
      $seriesByRes[$reso] = [
        'provider' => $provIn,
        'error'    => $e->getMessage(),
      ];
      if ($debug) $seriesByRes[$reso]['_hint'] = '403 suele ser: clave inválida/plan sin permiso, IP bloqueada, o rate limit. Intradía en Tiingo requiere IEX; AV intradía requiere premium; Finnhub intradía requiere plan con candles.';
    }
  }

  json_out([
    'provider'    => $provIn,
    'symbol'      => $symbol,
    'seriesByRes' => $seriesByRes,
  ], 200);

} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}
