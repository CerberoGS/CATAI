<?php
// /catai/api/test_news_provider_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $in = json_input(true) ?: [];
  $providerId = (int)($in['provider_id'] ?? 0);
  
  if ($providerId <= 0) {
    json_out(['error' => 'invalid-input', 'message' => 'ID de proveedor es requerido'], 400);
  }
  
  $pdo = db();
  
  // Obtener información del proveedor
  $stmt = $pdo->prepare('SELECT id, slug, name, base_url, url_request FROM news_providers WHERE id = ? AND is_enabled = 1');
  $stmt->execute([$providerId]);
  $provider = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$provider) {
    json_out(['error' => 'provider-not-found', 'message' => 'Proveedor de noticias no encontrado'], 404);
  }
  
  // Obtener la clave API del usuario
  $stmt = $pdo->prepare('SELECT api_key_enc FROM user_news_api_keys WHERE user_id = ? AND provider_id = ? AND status = "active"');
  $stmt->execute([$userId, $providerId]);
  $keyRecord = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$keyRecord) {
    json_out(['error' => 'key-not-found', 'message' => 'No se encontró clave API para este proveedor'], 404);
  }
  
  // Desencriptar la clave
  $apiKey = catai_decrypt($keyRecord['api_key_enc']);
  if (!$apiKey) {
    json_out(['error' => 'decrypt-failed', 'message' => 'Error al desencriptar la clave API'], 500);
  }
  
  // Función genérica para probar con URL personalizada
  function testWithCustomUrl($url, $apiKey) {
    // Reemplazar placeholders comunes
    $url = str_replace(['{API_KEY}', '{api_key}', '{token}', '{apikey}'], $apiKey, $url);
    
    // Si la URL termina con apiKey=, token=, o apikey=, concatenar la clave
    if (preg_match('/(apiKey=|token=|apikey=)$/', $url)) {
      $url .= urlencode($apiKey);
    }
    
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_USERAGENT => 'CATAI/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
      return ['success' => false, 'error' => $error, 'http_code' => $httpCode];
    }
    
    // Intentar decodificar JSON
    $data = json_decode($response, true);
    
    // Verificar si es exitoso basado en el código HTTP y contenido
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);
    
    // Verificaciones específicas para diferentes APIs
    if ($isSuccess && is_array($data)) {
      // NewsAPI
      if (isset($data['status']) && $data['status'] === 'ok') {
        $isSuccess = true;
      }
      // APIs que devuelven array de resultados
      elseif (isset($data['results']) || isset($data['articles']) || isset($data['data'])) {
        $isSuccess = true;
      }
      // APIs que devuelven error en el JSON
      elseif (isset($data['error']) || isset($data['message'])) {
        $isSuccess = false;
      }
    }
    
    return [
      'success' => $isSuccess,
      'http_code' => $httpCode,
      'response' => $data ?: $response,
      'url_tested' => $url
    ];
  }
  
  // Función genérica para probar proveedor
  function testGenericProvider($provider, $apiKey) {
    $baseUrl = rtrim($provider['base_url'], '/');
    $testUrls = [
      $baseUrl . '/health',
      $baseUrl . '/status',
      $baseUrl . '/ping',
      $baseUrl . '/test'
    ];
    
    foreach ($testUrls as $testUrl) {
      $result = testWithCustomUrl($testUrl, $apiKey);
      if ($result['success']) {
        return $result;
      }
    }
    
    return ['success' => false, 'error' => 'No se pudo conectar con el proveedor'];
  }
  
  // Probar el proveedor
  if (!empty($provider['url_request'])) {
    $result = testWithCustomUrl($provider['url_request'], $apiKey);
  } else {
    $result = testGenericProvider($provider, $apiKey);
  }
  
  json_out([
    'ok' => true,
    'provider' => $provider['name'],
    'test_result' => $result
  ]);
  
} catch (Exception $e) {
  error_log("Error en test_news_provider_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
