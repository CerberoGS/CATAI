<?php
// /bolsa/api/user_keys_set_safe.php
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

  $in = json_input(true) ?: [];

  // campos esperados (string o null/empty para borrar)
  $expect = [
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

  $saved = []; $deleted = []; $skipped = [];
  
  // Función para guardar clave con nuevo sistema de cifrado
  function set_api_key_external_crypto($userId, $provider, $apiKey) {
    try {
      $pdo = db();
      
      // Cifrar con el nuevo sistema
      $encryptedKey = catai_encrypt($apiKey);
      $keyFingerprint = hash('sha256', $apiKey);
      $last4 = substr($apiKey, -4);
      
      // Verificar si ya existe
      $checkStmt = $pdo->prepare('SELECT id FROM user_api_keys WHERE user_id = ? AND provider = ?');
      $checkStmt->execute([$userId, $provider]);
      $existing = $checkStmt->fetch();
      
      if ($existing) {
        // Actualizar existente
        $updateStmt = $pdo->prepare('UPDATE user_api_keys 
                                    SET api_key_enc = ?, 
                                        key_ciphertext = ?, 
                                        key_fingerprint = ?,
                                        last4 = ?,
                                        status = "active",
                                        updated_at = NOW()
                                    WHERE user_id = ? AND provider = ?');
        $updateStmt->execute([$encryptedKey, $encryptedKey, $keyFingerprint, $last4, $userId, $provider]);
      } else {
        // Insertar nuevo
        $insertStmt = $pdo->prepare('INSERT INTO user_api_keys 
                                    (user_id, provider, api_key_enc, key_ciphertext, key_fingerprint, last4, status, created_at, updated_at)
                                    VALUES (?, ?, ?, ?, ?, ?, "active", NOW(), NOW())');
        $insertStmt->execute([$userId, $provider, $encryptedKey, $encryptedKey, $keyFingerprint, $last4]);
      }
      
      return true;
    } catch (Exception $e) {
      error_log("Error setting API key for user $userId, provider $provider: " . $e->getMessage());
      return false;
    }
  }

  // Compatibilidad con dos formatos de payload:
  // 1) Campos en la raíz: { openai:"sk-...", gemini:"...", polygon:"..." }
  // 2) Objeto con set/delete: { set:{openai:"sk-..."}, delete:["polygon"] }

  // Borrados explícitos
  if (isset($in['delete']) && is_array($in['delete'])) {
    foreach ($in['delete'] as $provDel) {
      $provDel = strtolower(trim((string)$provDel));
      if (!isset($expect[$provDel])) continue;
      delete_api_key_for($userId, $provDel);
      $deleted[] = $provDel;
    }
  }

  // Set explícito via set{}
  if (isset($in['set']) && is_array($in['set'])) {
    foreach ($in['set'] as $provSet => $val) {
      $provSet = strtolower(trim((string)$provSet));
      if (!isset($expect[$provSet])) continue;
      $val = trim((string)$val);
      if ($val === '') { delete_api_key_for($userId, $provSet); $deleted[] = $provSet; continue; }
      set_api_key_external_crypto($userId, $provSet, $val);
      $saved[] = $provSet;
    }
  }

  // Campos de nivel raíz
  foreach ($expect as $prov => $env) {
    // Si ya se procesó por set/delete, saltar
    if (in_array($prov, $saved, true) || in_array($prov, $deleted, true)) continue;
    $val = isset($in[$prov]) ? trim((string)$in[$prov]) : null;
    if ($val === null) { $skipped[] = $prov; continue; }
    if ($val === '') { delete_api_key_for($userId, $prov); $deleted[] = $prov; }
    else { set_api_key_external_crypto($userId, $prov, $val); $saved[] = $prov; }
  }

  json_out(['ok'=>true, 'storage'=>'db', 'saved'=>$saved, 'deleted'=>$deleted, 'skipped'=>$skipped], 200);

} catch (Throwable $e) {
  // Registrar error en log seguro
  $logFile = __DIR__ . '/logs/safe.log';
  $msg = date('Y-m-d H:i:s') . " user_keys_set_safe.php ERROR: " . $e->getMessage() . "\nTRACE: " . $e->getTraceAsString() . "\n";
  file_put_contents($logFile, $msg, FILE_APPEND);
  json_out([
    'error'=>'user-keys-set-failed',
    'detail'=>'logged',
  ], 500);
}
