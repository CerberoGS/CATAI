<?php
// /catai/api/debug_test_provider_key_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';
require_once __DIR__ . '/lib/ProviderKeyTest.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $in = json_input(true) ?: [];
  $providerType = $in['provider_type'] ?? 'ai';
  $providerId = (int)($in['provider_id'] ?? 1);
  $projectFields = $in['project_fields'] ?? [];
  
  error_log("🐛 DEBUG testProviderKey - Provider Type: $providerType, Provider ID: $providerId");

  $pdo = db();
  
  // Determinar tabla según tipo
  $tableMap = [
    'data' => 'data_providers',
    'ai' => 'ai_providers', 
    'trade' => 'trade_providers',
    'news' => 'news_providers'
  ];
  
  $userTableMap = [
    'data' => 'user_data_api_keys',
    'ai' => 'user_ai_api_keys',
    'trade' => 'user_trade_api_keys',
    'news' => 'user_news_api_keys'
  ];
  
  $providerTable = $tableMap[$providerType] ?? 'ai_providers';
  $userTable = $userTableMap[$providerType] ?? 'user_ai_api_keys';
  
  error_log("🐛 DEBUG - Tabla de proveedores: $providerTable");
  error_log("🐛 DEBUG - Tabla de usuario: $userTable");
  
  // Obtener información del proveedor
  $stmt = $pdo->prepare("
    SELECT id, slug, name, category, auth_type, base_url, docs_url, 
           rate_limit_per_min, is_enabled, url_request, config_json
    FROM $providerTable 
    WHERE id = ?
  ");
  $stmt->execute([$providerId]);
  $providerRow = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$providerRow) {
    json_out(['error' => 'provider-not-found', 'message' => 'Proveedor no encontrado'], 404);
  }
  
  error_log("🐛 DEBUG - Proveedor encontrado: " . $providerRow['name']);
  error_log("🐛 DEBUG - Config JSON raw: " . $providerRow['config_json']);
  
  // Obtener clave del usuario
  $stmt = $pdo->prepare("
    SELECT api_key_enc, key_ciphertext, environment
    FROM $userTable 
    WHERE user_id = ? AND provider_id = ? AND origin = 'byok' AND status = 'active'
  ");
  $stmt->execute([$userId, $providerId]);
  $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$keyData) {
    json_out(['error' => 'key-not-found', 'message' => 'No se encontró clave activa'], 404);
  }
  
  // Desencriptar clave
  try {
    $apiKey = catai_decrypt($keyData['key_ciphertext'] ?? $keyData['api_key_enc']);
    error_log("🐛 DEBUG - Clave desencriptada, longitud: " . strlen($apiKey));
  } catch (Exception $e) {
    error_log("🐛 DEBUG - Error desencriptando: " . $e->getMessage());
    json_out(['error' => 'decrypt-failed', 'message' => 'Error al acceder a la clave'], 500);
  }
  
  // Función cURL wrapper con logging extra y validación defensiva
  $curl = function(string $url, array $opts): array {
    error_log("🐛 DEBUG cURL - URL recibida: '$url'");
    error_log("🐛 DEBUG cURL - URL length: " . strlen($url));
    error_log("🐛 DEBUG cURL - URL empty: " . (empty($url) ? 'YES' : 'NO'));
    error_log("🐛 DEBUG cURL - Opciones: " . json_encode(array_keys($opts)));
    
    if (empty($url)) {
      error_log("🐛 DEBUG cURL - ERROR: URL está vacía!");
      return [0, 'No URL set!', ''];
    }
    
    // Normalizar URL (práctica defensiva del senior)
    $url = str_replace(["\r", "\n"], '', $url);
    $url = trim($url);
    error_log("🐛 DEBUG cURL - URL normalizada: '$url'");
    
    // Blindar: SIEMPRE asegurar que CURLOPT_URL esté en las opciones
    $opts[CURLOPT_URL] = $url;  // <-- IMPRESCINDIBLE según senior
    $opts[CURLOPT_RETURNTRANSFER] = $opts[CURLOPT_RETURNTRANSFER] ?? true;
    error_log("🐛 DEBUG cURL - CURLOPT_URL configurado en opciones");
    
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    error_log("🐛 DEBUG cURL - Resultado: Code=$code, Error='$err'");
    
    return [$code, $err, $body];
  };
  
  // Ejecutar testProviderKey con logging extra
  error_log("🐛 DEBUG - Llamando a testProviderKey...");
  $result = testProviderKey($providerRow, $apiKey, $projectFields, $curl);
  error_log("🐛 DEBUG - Resultado de testProviderKey: " . json_encode($result));
  
  json_out([
    'ok' => true,
    'debug' => [
      'provider_row' => $providerRow,
      'config_json_parsed' => json_decode($providerRow['config_json'], true),
      'api_key_length' => strlen($apiKey),
      'project_fields' => $projectFields,
      'test_result' => $result
    ]
  ]);
  
} catch (Exception $e) {
  error_log("🐛 DEBUG - Error: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
