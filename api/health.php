<?php
require_once __DIR__ . '/helpers.php';
$config = require __DIR__ . '/config.php';

$path = $config['UNIVERSE_PATH'];
$exists = is_file($path);
$size = $exists ? filesize($path) : 0;

$helpers = __DIR__ . '/helpers.php';
$optfile = __DIR__ . '/options_safe.php';
$tsfile  = __DIR__ . '/time_series_safe.php';
$build = [
  'helpers_mtime' => @filemtime($helpers) ?: null,
  'options_mtime' => @filemtime($optfile) ?: null,
  'timeseries_mtime' => @filemtime($tsfile) ?: null,
  'functions' => [
    'app_timezone_for_user' => function_exists('app_timezone_for_user'),
    'market_hours_nyse'     => function_exists('market_hours_nyse'),
  ],
];
$tzSample = function_exists('app_timezone_for_user') ? app_timezone_for_user(0) : 'n/a';
$nyse = function_exists('market_hours_nyse') ? market_hours_nyse($tzSample ?: 'America/New_York') : null;

json_out([
  'ok' => true,
  'php_version' => PHP_VERSION,
  'build' => $build,
  'providers' => [
    'tiingo'        => !empty($config['TIINGO_API_KEY']),
    'alphavantage'  => !empty($config['ALPHAVANTAGE_API_KEY']),
    'finnhub'       => !empty($config['FINNHUB_API_KEY']),
    'gemini'        => !empty($config['GEMINI_API_KEY']),
    'local_universe'=> $exists && $size > 2
  ],
  'tz_default' => $tzSample,
  'nyse' => $nyse,
  'universe' => [
    'path' => $path,
    'exists' => $exists,
    'size' => $size
  ],
  'notes' => [
    'protected_endpoints' => ['time_series','options','ai_analyze'],
    'tip' => 'Si providers.gemini=false o finnhub=false, revisa api/config.php y coloca las API keys.'
  ]
]);
