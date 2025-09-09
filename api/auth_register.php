<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

$body = read_json_body();
$email = normalize_email($body['email'] ?? '');
$pass  = (string)($body['password'] ?? '');
$name  = trim((string)($body['name'] ?? ''));

if ($email === '' || $pass === '') json_out(['error'=>'email_password_required'], 400);
if (strlen($pass) < 8) json_out(['error'=>'weak_password','detail'=>'min_length_8'], 400);

$pdo = db();
$st = $pdo->prepare("SELECT id FROM users WHERE email=?");
$st->execute([$email]);
if ($st->fetch()) json_out(['error'=>'email_in_use'], 409);

$hash = password_hash($pass, PASSWORD_BCRYPT);
$pdo->prepare("INSERT INTO users (email,password_hash,name) VALUES (?,?,?)")->execute([$email,$hash,$name ?: null]);

$u = null;
try {
  $u = $pdo->query("SELECT id,email,name,is_admin FROM users WHERE email=".$pdo->quote($email))->fetch();
} catch (Throwable $e) {
  $u = $pdo->query("SELECT id,email,name FROM users WHERE email=".$pdo->quote($email))->fetch();
  if (is_array($u) && !isset($u['is_admin'])) { $u['is_admin'] = 0; }
}
$payload = ['id'=>$u['id'],'email'=>$u['email']];
if (isset($u['is_admin']) && (int)$u['is_admin'] === 1) { $payload['role']='admin'; $payload['is_admin']=true; }
$token = jwt_sign($payload);
json_out(['token'=>$token,'user'=>$u]);
