<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$u = require_user();
$pdo = db();
$st = $pdo->prepare("SELECT provider, api_key_enc FROM user_api_keys WHERE user_id=?");
$st->execute([$u['id']]);
$out = [];
foreach ($st as $r) $out[$r['provider']] = true;
json_out($out);
