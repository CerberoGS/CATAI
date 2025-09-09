<?php
require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';
$provider = strtolower($_GET['provider'] ?? 'auto');
$limit = min(intval($_GET['limit'] ?? 2000), 5000);

function read_local($path, $limit) {
  if (!is_file($path)) return [];
  $j = json_decode(file_get_contents($path), true);
  if (!is_array($j)) return [];
  return array_slice($j, 0, $limit);
}

try {
  if ($provider === 'local') {
    json_out(read_local($config['UNIVERSE_PATH'], $limit));
  }

  if ($provider === 'tiingo' || $provider === 'auto') {
    try {
      $url = 'https://api.tiingo.com/tiingo/daily';
      $data = http_get_json($url, ['Authorization: Token '.$config['TIINGO_API_KEY']]);
      $arr = [];
      foreach (($data ?? []) as $x) {
        if (!empty($x['ticker']) && !empty($x['name'])) {
          $arr[] = ['symbol'=>$x['ticker'], 'name'=>$x['name']];
          if (count($arr) >= $limit) break;
        }
      }
      if ($arr) json_out($arr);
    } catch (Exception $e) { /* fall-through */ }
  }

  if ($provider === 'alphavantage' || $provider === 'av' || $provider === 'auto') {
    try {
      // LISTING_STATUS devuelve CSV; lo parseamos
      $url = 'https://www.alphavantage.co/query?function=LISTING_STATUS&state=active&apikey='.$config['ALPHAVANTAGE_API_KEY'];
      $csv = http_get_text($url);
      $lines = explode("\n", $csv);
      $arr = [];
      // skip encabezado
      for ($i=1; $i<count($lines); $i++) {
        $cols = str_getcsv($lines[$i]);
        if (count($cols) < 2) continue;
        $sym = trim($cols[0] ?? ''); $name = trim($cols[1] ?? '');
        if ($sym && $name) {
          $arr[] = ['symbol'=>$sym, 'name'=>$name];
          if (count($arr) >= $limit) break;
        }
      }
      if ($arr) json_out($arr);
    } catch (Exception $e) { /* fall-through */ }
  }

  // fallback final
  json_out(read_local($config['UNIVERSE_PATH'], $limit));
} catch (Exception $e) {
  json_out(['error'=>'universe_failed','detail'=>$e->getMessage()], 500);
}
