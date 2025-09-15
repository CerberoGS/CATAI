<?php
declare(strict_types=1);

// Test específico para debuggear problemas de auth
try {
  // Test 1: Verificar que los archivos requeridos existen
  $files = [
    'helpers.php' => file_exists(__DIR__ . '/helpers.php'),
    'db.php' => file_exists(__DIR__ . '/db.php'),
    'jwt.php' => file_exists(__DIR__ . '/jwt.php'),
    'config.php' => file_exists(__DIR__ . '/config.php')
  ];

  // Test 2: Verificar que las funciones existen
  $functions = [];
  if ($files['helpers.php']) {
    require_once __DIR__ . '/helpers.php';
    $functions['json_out'] = function_exists('json_out');
    $functions['read_json_body'] = function_exists('read_json_body');
    $functions['normalize_email'] = function_exists('normalize_email');
  }

  if ($files['jwt.php']) {
    require_once __DIR__ . '/jwt.php';
    $functions['jwt_sign'] = function_exists('jwt_sign');
  }

  if ($files['db.php']) {
    require_once __DIR__ . '/db.php';
    $functions['db'] = function_exists('db');
  }

  // Test 3: Verificar configuración
  $config = [];
  if ($files['config.php']) {
    $conf = require __DIR__ . '/config.php';
    $config['loaded'] = true;
    $config['jwt_secret_set'] = !empty($conf['JWT_SECRET']);
    $config['db_host'] = $conf['DB_HOST'] ?? 'NOT_SET';
    $config['db_name'] = $conf['DB_NAME'] ?? 'NOT_SET';
  }

  // Test 4: Verificar conexión a base de datos
  $dbTest = [];
  try {
    if (function_exists('db')) {
      $pdo = db();
      $dbTest['connection'] = 'SUCCESS';
      $dbTest['pdo_class'] = get_class($pdo);
      
      // Test simple de query
      $stmt = $pdo->query("SELECT 1 as test");
      $result = $stmt->fetch();
      $dbTest['query_test'] = $result['test'] == 1 ? 'SUCCESS' : 'FAILED';
    } else {
      $dbTest['connection'] = 'DB_FUNCTION_NOT_FOUND';
    }
  } catch (Throwable $e) {
    $dbTest['connection'] = 'FAILED';
    $dbTest['error'] = $e->getMessage();
  }

  // Test 5: Verificar JWT
  $jwtTest = [];
  try {
    if (function_exists('jwt_sign')) {
      $testPayload = ['test' => true, 'timestamp' => time()];
      $testToken = jwt_sign($testPayload);
      $jwtTest['sign'] = 'SUCCESS';
      $jwtTest['token_length'] = strlen($testToken);
    } else {
      $jwtTest['sign'] = 'FUNCTION_NOT_FOUND';
    }
  } catch (Throwable $e) {
    $jwtTest['sign'] = 'ERROR';
    $jwtTest['error'] = $e->getMessage();
  }

  // Respuesta JSON
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  
  echo json_encode([
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'debug' => [
      'files' => $files,
      'functions' => $functions,
      'config' => $config,
      'database' => $dbTest,
      'jwt' => $jwtTest
    ],
    'message' => 'Debug de auth completado'
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  
  echo json_encode([
    'ok' => false,
    'error' => 'debug_failed',
    'detail' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'timestamp' => date('Y-m-d H:i:s')
  ], JSON_UNESCAPED_UNICODE);
}
