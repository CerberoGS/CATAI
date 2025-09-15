<?php
// Test simple para verificar que config.php funciona
try {
  $conf = require __DIR__ . '/config.php';
  
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  
  echo json_encode([
    'ok' => true,
    'config_loaded' => true,
    'base_url' => $conf['BASE_URL'] ?? 'NOT_SET',
    'api_base_url' => $conf['API_BASE_URL'] ?? 'NOT_SET',
    'timestamp' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE);
  
} catch (Throwable $e) {
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'timestamp' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE);
}
