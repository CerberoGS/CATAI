<?php
// jwt.php
$config = require __DIR__ . '/config.php';

function jwt_sign(array $payload, $expDays=7) {
  global $config;
  $header = ['typ'=>'JWT','alg'=>'HS256'];
  $payload['exp'] = time() + 86400 * $expDays;
  $b64 = function ($data) {
    return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
  };
  $h = $b64($header);
  $p = $b64($payload);
  $sig = hash_hmac('sha256', "$h.$p", $config['JWT_SECRET'], true);
  $s = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
  return "$h.$p.$s";
}

function jwt_decode_user($token) {
  global $config;
  $parts = explode('.', $token);
  if (count($parts) !== 3) return null;
  [$h, $p, $s] = $parts;
  $sig = base64_decode(strtr($s, '-_', '+/'));
  $calc = hash_hmac('sha256', "$h.$p", $config['JWT_SECRET'], true);
  if (!hash_equals($sig, $calc)) return null;
  $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
  if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
  return ['id'=>$payload['id'] ?? null, 'email'=>$payload['email'] ?? null];
}
