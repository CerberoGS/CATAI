<?php
// /bolsa/api/user_keys_get_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/Crypto_safe.php';

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

  // Función para obtener clave con nuevo sistema de cifrado
  function get_api_key_external_crypto($userId, $provider) {
    try {
      $pdo = db();
      $stmt = $pdo->prepare('SELECT api_key_enc, last4, status FROM user_api_keys 
                            WHERE user_id = ? AND provider = ? AND status = "active"');
      $stmt->execute([$userId, $provider]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$row) {
        return null;
      }
      
      // Descifrar con el nuevo sistema
      $decryptedKey = catai_decrypt($row['api_key_enc']);
      return [
        'key' => $decryptedKey,
        'last4' => $row['last4'],
        'status' => $row['status']
      ];
    } catch (Exception $e) {
      error_log("Error getting API key for user $userId, provider $provider: " . $e->getMessage());
      return null;
    }
  }

  $keys = [];
  foreach ($providers as $prov => $env) {
    $k = get_api_key_external_crypto($userId, $prov);
    $keys[$prov] = [
      'has'   => !empty($k),
      'last4' => $k['last4'] ?? null,
      'status' => $k['status'] ?? null,
    ];
  }

  json_out(['ok'=>true, 'storage'=>'db', 'keys'=>$keys], 200);

} catch (Throwable $e) {
  json_out(['error'=>'user-keys-get-failed','detail'=>$e->getMessage()], 500);
}
