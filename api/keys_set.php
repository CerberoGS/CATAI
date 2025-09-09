<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

$u = require_user();
$body = read_json_body();
$provider = $body['provider'] ?? '';
$api_key  = $body['api_key'] ?? '';
if ($provider === '' || $api_key === '') json_out(['error'=>'provider_and_api_key_required'], 400);

$enc = encrypt_text($api_key);
$pdo = db();
$pdo->prepare("
  INSERT INTO user_api_keys (user_id, provider, api_key_enc)
  VALUES (?,?,?)
  ON DUPLICATE KEY UPDATE api_key_enc=VALUES(api_key_enc), updated_at=CURRENT_TIMESTAMP
")->execute([$u['id'], $provider, $enc]);

json_out(['ok'=>true]);
