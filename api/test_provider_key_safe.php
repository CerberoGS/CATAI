<?php
// /catai/api/test_provider_key_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';
require_once __DIR__ . '/lib/ProviderKeyTest.php';

json_header();

try {
  // Logging de headers para debug
  error_log("🧪 test_provider_key_safe.php - Headers recibidos:");
  error_log("📋 Authorization: " . ($_SERVER['HTTP_AUTHORIZATION'] ?? 'NO ENCONTRADO'));
  error_log("📋 REDIRECT_HTTP_AUTHORIZATION: " . ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NO ENCONTRADO'));
  error_log("📋 REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'NO ENCONTRADO'));
  
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $in = json_input(true) ?: [];
  
  // Logging inicial
  error_log("🧪 test_provider_key_safe.php - Inicio de prueba");
  error_log("👤 User ID: $userId");
  error_log("📥 Input recibido: " . json_encode($in));
  
  // Validar campos requeridos
  $providerType = $in['provider_type'] ?? '';
  $providerId = (int)($in['provider_id'] ?? 0);
  $projectFields = $in['project_fields'] ?? [];
  
  error_log("🔍 Provider Type: $providerType");
  error_log("🔍 Provider ID: $providerId");
  error_log("🔍 Project Fields: " . json_encode($projectFields));
  
  if (empty($providerType) || $providerId <= 0) {
    error_log("❌ Parámetros inválidos");
    json_out(['error' => 'invalid-params', 'message' => 'provider_type y provider_id son requeridos'], 400);
  }

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
  
  if (!isset($tableMap[$providerType])) {
    error_log("❌ Tipo de proveedor inválido: $providerType");
    json_out(['error' => 'invalid-provider-type', 'message' => 'Tipo de proveedor no válido'], 400);
  }
  
  $providerTable = $tableMap[$providerType];
  $userTable = $userTableMap[$providerType];
  
  error_log("📊 Tabla de proveedores: $providerTable");
  error_log("📊 Tabla de usuario: $userTable");
  
  // Obtener información del proveedor
  $stmt = $pdo->prepare("
    SELECT id, slug, name, category, auth_type, base_url, docs_url, 
           rate_limit_per_min, is_enabled, url_request, config_json
    FROM $providerTable 
    WHERE id = ? AND is_enabled = 1
  ");
  $stmt->execute([$providerId]);
  $providerRow = $stmt->fetch(PDO::FETCH_ASSOC);
  
  error_log("🔍 Proveedor encontrado: " . ($providerRow ? 'SÍ' : 'NO'));
  if ($providerRow) {
    error_log("📋 Proveedor: " . $providerRow['name'] . " (" . $providerRow['slug'] . ")");
    error_log("⚙️ Config JSON: " . substr($providerRow['config_json'] ?? 'null', 0, 200));
  }
  
  if (!$providerRow) {
    error_log("❌ Proveedor no encontrado o deshabilitado");
    json_out(['error' => 'provider-not-found', 'message' => 'Proveedor no encontrado o deshabilitado'], 404);
  }
  
  // Obtener clave del usuario
  $stmt = $pdo->prepare("
    SELECT api_key_enc, key_ciphertext, environment, error_count
    FROM $userTable 
    WHERE user_id = ? AND provider_id = ? AND origin = 'byok' AND status = 'active'
  ");
  $stmt->execute([$userId, $providerId]);
  $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
  
  error_log("🔑 Clave encontrada: " . ($keyData ? 'SÍ' : 'NO'));
  if ($keyData) {
    error_log("🔍 Environment: " . ($keyData['environment'] ?? 'N/A'));
    error_log("🔍 Error count: " . ($keyData['error_count'] ?? 'N/A'));
  }
  
  if (!$keyData) {
    error_log("❌ No se encontró clave activa para este proveedor");
    json_out(['error' => 'key-not-found', 'message' => 'No se encontró una clave activa para este proveedor'], 404);
  }
  
  // Desencriptar la clave
  try {
    error_log("🔓 Desencriptando clave...");
    $apiKey = catai_decrypt($keyData['api_key_enc']);
    
    if (empty($apiKey)) {
      throw new Exception('Clave vacía después del descifrado');
    }
    
    error_log("✅ Clave desencriptada exitosamente, longitud: " . strlen($apiKey));
  } catch (Exception $e) {
    error_log("❌ Error desencriptando clave: " . $e->getMessage());
    json_out(['error' => 'decrypt-failed', 'message' => 'Error al acceder a la clave: ' . $e->getMessage()], 500);
  }
  
  // Función cURL wrapper robusta
  $curl = function(string $url, array $opts): array {
    error_log("🌐 Ejecutando cURL a: $url");
    error_log("⚙️ Opciones cURL: " . json_encode(array_keys($opts)));
    
    // Normalizar URL (práctica defensiva)
    $url = str_replace(["\r", "\n"], '', $url);
    $url = trim($url);
    
    // Blindar: SIEMPRE asegurar que CURLOPT_URL esté en las opciones
    $opts[CURLOPT_URL] = $url;  // <-- IMPRESCINDIBLE según senior
    $opts[CURLOPT_RETURNTRANSFER] = $opts[CURLOPT_RETURNTRANSFER] ?? true;
    
    error_log("🔧 URL final en CURLOPT_URL: '$url'");
    
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    error_log("📊 Resultado cURL - Código: $code, Error: " . ($err ?: 'Ninguno'));
    
    return [$code, $err, $body];
  };
  
  // Ejecutar prueba genérica
  error_log("🚀 Iniciando prueba genérica...");
  $result = testProviderKey($providerRow, $apiKey, $projectFields, $curl);
  
  error_log("📊 Resultado final: " . json_encode($result));
  
  // Actualizar error_count si es necesario
  if (!$result['ok']) {
    $stmt = $pdo->prepare("
      UPDATE $userTable 
      SET error_count = error_count + 1, last_used_at = NOW() 
      WHERE user_id = ? AND provider_id = ?
    ");
    $stmt->execute([$userId, $providerId]);
    error_log("📈 Error count incrementado");
  } else {
    $stmt = $pdo->prepare("
      UPDATE $userTable 
      SET error_count = 0, last_used_at = NOW() 
      WHERE user_id = ? AND provider_id = ?
    ");
    $stmt->execute([$userId, $providerId]);
    error_log("✅ Error count resetado");
  }
  
  // Respuesta final
  $response = [
    'ok' => $result['ok'],
    'provider_name' => $providerRow['name'],
    'provider_slug' => $providerRow['slug'],
    'http_code' => $result['http_code'],
    'reason' => $result['reason'],
    'body_snippet' => $result['body_snippet'] ?? '',
    'debug' => $result['debug'] ?? '',
    'duration_ms' => $result['duration_ms'] ?? null,
    'environment' => $keyData['environment'] ?? 'live'
  ];
  
  error_log("🎯 Enviando respuesta: " . json_encode($response));
  json_out($response);
  
} catch (Exception $e) {
  error_log("💥 Error en test_provider_key_safe.php: " . $e->getMessage());
  error_log("📍 Stack trace: " . $e->getTraceAsString());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
