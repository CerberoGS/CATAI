<?php
// /bolsa/api/test_api_key_functions_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error' => 'invalid-user'], 401);

  $in = json_input();
  $provider = strtolower(trim((string)($in['provider'] ?? '')));

  if ($provider === '') json_out(['error'=>'provider-required'], 400);

  // Obtener la clave cifrada directamente de la BD
  if (!function_exists('db')) require_once __DIR__ . '/db.php';
  $pdo = db();
  $st = $pdo->prepare("SELECT api_key_enc FROM user_api_keys WHERE user_id = ? AND provider = ? ORDER BY id DESC LIMIT 1");
  $st->execute([$userId, $provider]);
  $row = $st->fetch();
  
  if (!$row || !$row['api_key_enc']) {
    json_out(['error'=>'no-key-found','detail'=>'No se encontró clave para ' . $provider], 400);
  }
  
  $apiKeyEnc = $row['api_key_enc'];
  
  // PRUEBA 1: Usar get_api_key_for()
  $keyFromHelper = get_api_key_for($userId, $provider);
  
  // PRUEBA 2: Usar decrypt_text() directamente
  $keyFromDecrypt = decrypt_text($apiKeyEnc);
  
  // Resultados de las pruebas
  json_out([
    'ok' => true,
    'provider' => $provider,
    'user_id' => $userId,
    'api_key_enc_length' => strlen($apiKeyEnc),
    'api_key_enc_preview' => substr($apiKeyEnc, 0, 50) . '...',
    'test_results' => [
      'get_api_key_for' => [
        'success' => $keyFromHelper !== '',
        'key_length' => strlen($keyFromHelper),
        'key_preview' => $keyFromHelper !== '' ? substr($keyFromHelper, 0, 10) . '...' . substr($keyFromHelper, -4) : 'EMPTY',
        'key_full' => $keyFromHelper
      ],
      'decrypt_text' => [
        'success' => $keyFromDecrypt !== '',
        'key_length' => strlen($keyFromDecrypt),
        'key_preview' => $keyFromDecrypt !== '' ? substr($keyFromDecrypt, 0, 10) . '...' . substr($keyFromDecrypt, -4) : 'EMPTY',
        'key_full' => $keyFromDecrypt
      ]
    ],
    'comparison' => [
      'both_same' => $keyFromHelper === $keyFromDecrypt,
      'both_empty' => $keyFromHelper === '' && $keyFromDecrypt === '',
      'helper_works' => $keyFromHelper !== '',
      'decrypt_works' => $keyFromDecrypt !== ''
    ]
  ], 200);

} catch (Throwable $e) {
  json_out(['error'=>'test-failed','detail'=>$e->getMessage()], 500);
}
