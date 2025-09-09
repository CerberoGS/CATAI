<?php
// db_check.php
// Diagnóstico rápido de MySQL y tablas
header('Content-Type: application/json; charset=utf-8');

try {
  $config = require __DIR__ . '/config.php';

  $ext = [
    'pdo'        => extension_loaded('pdo'),
    'pdo_mysql'  => extension_loaded('pdo_mysql'),
    'curl'       => extension_loaded('curl'),
    'openssl'    => extension_loaded('openssl'),
    'json'       => extension_loaded('json'),
  ];

  $dsn = "mysql:host={$config['DB_HOST']};port={$config['DB_PORT']};dbname={$config['DB_NAME']};charset=utf8mb4";

  $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Lista de tablas
  $tables = [];
  foreach ($pdo->query("SHOW TABLES") as $row) {
    $tables[] = array_values($row)[0];
  }

  // Describe users si existe
  $users_desc = null;
  if (in_array('users', $tables)) {
    $users_desc = $pdo->query("DESCRIBE users")->fetchAll();
  }

  echo json_encode([
    'ok' => true,
    'php_version' => PHP_VERSION,
    'extensions' => $ext,
    'db' => [
      'host' => $config['DB_HOST'],
      'port' => $config['DB_PORT'],
      'name' => $config['DB_NAME'],
      'connected' => true,
    ],
    'tables' => $tables,
    'users_schema' => $users_desc,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'db_check_failed',
    'detail' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
