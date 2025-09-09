<?php
/**
 * _safe_wrapper.php — fuerza salida JSON y logging para cualquier fatal/exception.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$__DEBUG = isset($_GET['debug']);

if ($__DEBUG) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
}

function _safe_log($payload) {
  try {
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $trace = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $row = [
      'trace_id' => $trace,
      'time' => date('c'),
      'uri' => $_SERVER['REQUEST_URI'] ?? '',
      'payload' => $payload,
    ];
    @file_put_contents($dir.'/safe.log', json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    return $trace;
  } catch (\Throwable $e) {
    return null;
  }
}

register_shutdown_function(function () use ($__DEBUG) {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    $trace = _safe_log(['fatal'=>$e]);
    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8', true);
    }
    echo json_encode([
      'error' => 'fatal',
      'detail' => $__DEBUG ? ($e['message'] ?? 'internal_error') : 'internal_error',
      'trace_id' => $trace,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
});

set_exception_handler(function (\Throwable $ex) use ($__DEBUG) {
  $trace = _safe_log(['exception'=>['msg'=>$ex->getMessage(),'file'=>$ex->getFile(),'line'=>$ex->getLine(),'trace'=>$ex->getTraceAsString()]]);
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8', true);
  }
  echo json_encode([
    'error' => 'internal_exception',
    'detail' => $__DEBUG ? $ex->getMessage() : 'internal_error',
    'trace_id' => $trace,
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
});

function _safe_include(string $file): void {
  $path = __DIR__ . '/' . ltrim($file, '/');
  if (!is_file($path)) {
    if (!headers_sent()) http_response_code(404);
    echo json_encode(['error'=>'not_found','detail'=>basename($file).' missing'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }
  require $path;
}
