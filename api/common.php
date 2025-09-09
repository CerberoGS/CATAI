<?php
declare(strict_types=1);
header_remove('X-Powered-By');

function json_header() {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

function data_dir(): string {
  $dir = __DIR__ . '/data';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $ht = $dir . '/.htaccess';
  if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");
  return $dir;
}

function read_json_file(string $name, array $fallback = []): array {
  $file = data_dir() . '/' . $name;
  if (!is_file($file)) return $fallback;
  $txt = file_get_contents($file);
  $arr = json_decode($txt, true);
  return is_array($arr) ? $arr : $fallback;
}
function write_json_file(string $name, array $data): void {
  $file = data_dir() . '/' . $name;
  @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function json_input(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?? '', true);
  return is_array($data) ? $data : [];
}

function bad_request(string $msg, int $code=400): void {
  json_header();
  http_response_code($code);
  echo json_encode(['error'=>$msg]);
  exit;
}

function password_hash_safe(string $pass): string { return password_hash($pass, PASSWORD_DEFAULT); }
function password_verify_safe(string $pass, string $hash): bool { return password_verify($pass, $hash); }

function tokens_all(): array { return read_json_file('tokens.json', []); }
function tokens_save(array $t): void { write_json_file('tokens.json', $t); }

function make_token(string $uid): string {
  $tok = bin2hex(random_bytes(16));
  $tokens = tokens_all();
  $tokens[$tok] = ['uid'=>$uid, 'iat'=>time()];
  tokens_save($tokens);
  return $tok;
}

function bearer_token(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? null;
  if (!$h && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    foreach ($headers as $k=>$v) if (strtolower($k)==='authorization') { $h = $v; break; }
  }
  if (!$h) return null;
  if (stripos($h, 'Bearer ') === 0) return substr($h, 7);
  return $h;
}

function require_user(): array {
  $tok = bearer_token();
  if (!$tok) bad_request('missing_token', 401);
  $tokens = tokens_all();
  $rec = $tokens[$tok] ?? null;
  if (!$rec) bad_request('invalid_token', 401);
  $users = read_json_file('users.json', []);
  $uid = $rec['uid'] ?? '';
  $u = $users[$uid] ?? null;
  if (!$u) bad_request('user_not_found', 401);
  return ['user'=>$u, 'token'=>$tok];
}

function users_all(): array { return read_json_file('users.json', []); }
function users_save(array $u): void { write_json_file('users.json', $u); }
function normalize_email(string $e): string { return strtolower(trim($e)); }
