<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// CORS + JSON
$conf = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($conf['ALLOWED_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  // Test 1: Verificar que config.php carga correctamente
  $configTest = [
    'config_loaded' => true,
    'base_url' => $conf['BASE_URL'] ?? 'NOT_SET',
    'api_base_url' => $conf['API_BASE_URL'] ?? 'NOT_SET',
    'db_host' => $conf['DB_HOST'] ?? 'NOT_SET',
    'db_name' => $conf['DB_NAME'] ?? 'NOT_SET',
    'jwt_secret_set' => !empty($conf['JWT_SECRET']),
    'allowed_origin' => $conf['ALLOWED_ORIGIN'] ?? 'NOT_SET'
  ];

  // Test 2: Verificar conexión a base de datos
  $dbTest = [];
  try {
    $pdo = db();
    $dbTest['db_connection'] = 'SUCCESS';
    $dbTest['pdo_class'] = get_class($pdo);
  } catch (Throwable $e) {
    $dbTest['db_connection'] = 'FAILED';
    $dbTest['error'] = $e->getMessage();
  }

  // Test 3: Verificar tabla users
  $usersTest = [];
  try {
    if (isset($pdo)) {
      $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
      $result = $stmt->fetch();
      $usersTest['table_exists'] = true;
      $usersTest['user_count'] = (int)$result['count'];
    } else {
      $usersTest['table_exists'] = false;
      $usersTest['error'] = 'No database connection';
    }
  } catch (Throwable $e) {
    $usersTest['table_exists'] = false;
    $usersTest['error'] = $e->getMessage();
  }

  // Test 4: Verificar funciones JWT
  $jwtTest = [];
  try {
    if (function_exists('jwt_sign')) {
      $testPayload = ['test' => true, 'timestamp' => time()];
      $testToken = jwt_sign($testPayload);
      $jwtTest['jwt_sign'] = 'SUCCESS';
      $jwtTest['token_length'] = strlen($testToken);
      
      if (function_exists('jwt_verify')) {
        $verified = jwt_verify($testToken);
        $jwtTest['jwt_verify'] = $verified ? 'SUCCESS' : 'FAILED';
      } else {
        $jwtTest['jwt_verify'] = 'FUNCTION_NOT_FOUND';
      }
    } else {
      $jwtTest['jwt_sign'] = 'FUNCTION_NOT_FOUND';
    }
  } catch (Throwable $e) {
    $jwtTest['jwt_sign'] = 'ERROR';
    $jwtTest['error'] = $e->getMessage();
  }

  // Test 5: Verificar funciones helper
  $helperTest = [];
  try {
    $helperTest['normalize_email'] = function_exists('normalize_email') ? 'EXISTS' : 'NOT_FOUND';
    $helperTest['read_json_body'] = function_exists('read_json_body') ? 'EXISTS' : 'NOT_FOUND';
    $helperTest['json_out'] = function_exists('json_out') ? 'EXISTS' : 'NOT_FOUND';
  } catch (Throwable $e) {
    $helperTest['error'] = $e->getMessage();
  }

  json_out([
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'diagnostic' => [
      'config' => $configTest,
      'database' => $dbTest,
      'users_table' => $usersTest,
      'jwt_functions' => $jwtTest,
      'helper_functions' => $helperTest
    ],
    'message' => 'Diagnóstico de login completado'
  ]);

} catch (Throwable $e) {
  json_out([
    'ok' => false,
    'error' => 'diagnostic_failed',
    'detail' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
    'timestamp' => date('Y-m-d H:i:s')
  ], 500);
}
