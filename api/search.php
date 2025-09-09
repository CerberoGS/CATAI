<?php
require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';
$q = trim($_GET['q'] ?? '');
$provider = strtolower($_GET['provider'] ?? 'auto');
if ($q === '') json_out([]);

function search_local($path, $q) {
  if (!is_file($path)) return [];
  $data = json_decode(file_get_contents($path), true);
  if (!is_array($data)) return [];
  $qU = mb_strtoupper($q);
  $out = [];
  foreach ($data as $x) {
    $sym = mb_strtoupper($x['symbol'] ?? '');
    $name = mb_strtoupper($x['name'] ?? '');
    if (str_starts_with($sym, $qU) || str_contains($name, $qU)) {
      $out[] = ['symbol'=>$x['symbol'], 'name'=>$x['name']];
      if (count($out) >= 30) break;
    }
  }
  return $out;
}

try {
  if ($provider === 'local') {
    json_out(search_local($config['UNIVERSE_PATH'], $q));
  }
  if ($provider === 'tiingo' || $provider === 'auto') {
    try {
      $url = 'https://api.tiingo.com/tiingo/utilities/search?query='.rawurlencode($q);
      $data = http_get_json($url, ['Authorization: Token '.$config['TIINGO_API_KEY']]);
      $arr = [];
      foreach (($data ?? []) as $x) {
        $arr[] = ['symbol'=>$x['ticker'] ?? '', 'name'=>$x['name'] ?? ''];
        if (count($arr) >= 30) break;
      }
      if ($arr) json_out($arr);
    } catch (Exception $e) { /* fallback */ }
  }
  if ($provider === 'alphavantage' || $provider === 'av' || $provider === 'auto') {
    try {
      $url = 'https://www.alphavantage.co/query?function=SYMBOL_SEARCH&keywords='.rawurlencode($q).'&apikey='.$config['ALPHAVANTAGE_API_KEY'];
      $j = http_get_json($url);
      $arr = [];
      foreach (($j['bestMatches'] ?? []) as $m) {
        $arr[] = ['symbol'=>$m['1. symbol'] ?? '', 'name'=>$m['2. name'] ?? ''];
        if (count($arr) >= 30) break;
      }
      if ($arr) json_out($arr);
    } catch (Exception $e) { /* fallback */ }
  }
  json_out(search_local($config['UNIVERSE_PATH'], $q));
} catch (Exception $e) {
  json_out(['error'=>'search_failed','detail'=>$e->getMessage()], 500);
}
