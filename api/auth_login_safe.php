<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// /bolsa/api/auth_login_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Fuerza cabeceras JSON y no cache
json_header();

// Solo POST con JSON
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  json_out(['error' => 'method_not_allowed'], 405);
}

// Lee cuerpo JSON
$in    = json_input();
$email = normalize_email($in['email'] ?? '');
$pass  = (string)($in['password'] ?? '');

if ($email === '' || $pass === '') {
  json_out(['error' => 'missing_fields'], 400);
}

try {
  $pdo = db();

  // Buscar usuario y verificar credenciales
  $st = $pdo->prepare('SELECT id, email, password_hash, name, is_active FROM users WHERE email = ? LIMIT 1');
  $st->execute([$email]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u || (int)$u['is_active'] !== 1 || empty($u['password_hash']) || !password_verify($pass, $u['password_hash'])) {
    json_out(['error' => 'invalid_credentials'], 401);
  }

  // Generar JWT
  $token = jwt_sign(['id' => (int)$u['id'], 'email' => $u['email']]);
  json_out([
    'token' => $token,
    'user'  => [
      'id'    => (int)$u['id'],
      'email' => $u['email'],
      'name'  => $u['name'] ?? ''
    ]
  ], 200);

} catch (Throwable $e) {
  // No exponemos internals; 500 genérico
  json_out(['error' => 'server_error'], 500);
}
