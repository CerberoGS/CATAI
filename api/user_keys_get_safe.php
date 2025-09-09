<?php
// /bolsa/api/user_keys_get_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $providers = [
    'gemini'       => 'GEMINI_API_KEY',
    'openai'       => 'OPENAI_API_KEY',
    'xai'          => 'XAI_API_KEY',
    'claude'       => 'ANTHROPIC_API_KEY',
    'deepseek'     => 'DEEPSEEK_API_KEY',
    'tiingo'       => 'TIINGO_API_KEY',
    'finnhub'      => 'FINNHUB_API_KEY',
    'alphavantage' => 'ALPHAVANTAGE_API_KEY',
    'polygon'      => 'POLYGON_API_KEY',
  ];

  $keys = [];
  foreach ($providers as $prov => $env) {
    $k = get_api_key_for($userId, $prov, $env);
    $keys[$prov] = [
      'has'   => $k !== '',
      'last4' => $k !== '' ? substr($k, -4) : null,
    ];
  }

  json_out(['ok'=>true, 'storage'=>'db', 'keys'=>$keys], 200);

} catch (Throwable $e) {
  json_out(['error'=>'user-keys-get-failed','detail'=>$e->getMessage()], 500);
}
