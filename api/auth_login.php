<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// CORS + JSON SIEMPRE (también para preflight)
$conf = require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($conf['ALLOWED_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Convertir cualquier error en JSON coherente
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  if (!(error_reporting() & $errno)) return false;
  json_out(['error' => 'php_error', 'detail' => "$errstr @ $errfile:$errline"], 500);
});
set_exception_handler(function (Throwable $e) {
  json_out(['error' => 'internal_exception', 'detail' => $e->getMessage()], 500);
});
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    json_out(['error' => 'fatal', 'detail' => $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']], 500);
  }
});

// --- Lógica de login (igual a la tuya, con LIMIT 1) ---
$body  = read_json_body();
$email = normalize_email((string)($body['email'] ?? ''));
$pass  = (string)($body['password'] ?? '');

if ($email === '' || $pass === '') {
  json_out(['error' => 'email_password_required'], 400);
}

$pdo = db();
// Intentar leer is_admin si existe; si no, fallback sin la columna
$u = null;
try {
  $st = $pdo->prepare("SELECT id, email, password_hash, name, is_active, is_admin FROM users WHERE email = ? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();
} catch (Throwable $e) {
  $st = $pdo->prepare("SELECT id, email, password_hash, name, is_active FROM users WHERE email = ? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();
}

if (!$u || (int)$u['is_active'] !== 1 || empty($u['password_hash']) || !password_verify($pass, $u['password_hash'])) {
  json_out(['error' => 'invalid_credentials'], 401);
}

// Determinar admin por DB o lista del config
$isAdmin = false;
try {
  $isAdmin = (isset($u['is_admin']) && (int)$u['is_admin'] === 1);
  if (!$isAdmin && isset($conf['ADMIN_EMAILS']) && is_array($conf['ADMIN_EMAILS'])) {
    $isAdmin = in_array($u['email'], $conf['ADMIN_EMAILS'], true);
  }
} catch (Throwable $e) { $isAdmin = false; }

// Firmar JWT con claims extras
$payload = ['id' => (int)$u['id'], 'email' => $u['email']];
if ($isAdmin) { $payload['role'] = 'admin'; $payload['is_admin'] = true; }
$token = jwt_sign($payload);

json_out([
  'token' => $token,
  'user'  => [
    'id'    => (int)$u['id'],
    'email' => $u['email'],
    'name'  => $u['name'] ?? ''
  ]
], 200);

