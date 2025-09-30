<?php
// /catai/api/test_data_provider_safe.php
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
  
  // Logging para debugging
  error_log("DEBUG test_data_provider_safe.php - Input recibido: " . json_encode($in));
  error_log("DEBUG test_data_provider_safe.php - User ID: " . $userId);
  
  // Validar campos requeridos
  $providerId = (int)($in['provider_id'] ?? 0);
  
  error_log("DEBUG test_data_provider_safe.php - Provider ID: " . $providerId);
  
  if ($providerId <= 0) {
    error_log("ERROR test_data_provider_safe.php - Provider ID inválido: " . $providerId);
    json_out(['error' => 'invalid-provider', 'message' => 'ID de proveedor inválido'], 400);
  }

  $pdo = db();
  
  error_log("DEBUG test_data_provider_safe.php - Buscando clave para user_id: $userId, provider_id: $providerId");
  
  // Obtener información del proveedor y la clave del usuario
  $stmt = $pdo->prepare('
    SELECT 
      dak.api_key_enc,
      dak.key_ciphertext,
      dak.environment,
      dak.error_count,
      dp.slug,
      dp.label as provider_label,
      dp.base_url,
      dp.auth_type,
      dp.url_request
    FROM user_data_api_keys dak
    JOIN data_providers dp ON dak.provider_id = dp.id
    WHERE dak.user_id = ? AND dak.provider_id = ? AND dak.origin = "byok" AND dak.status = "active"
  ');
  $stmt->execute([$userId, $providerId]);
  $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
  
  error_log("DEBUG test_data_provider_safe.php - Query ejecutada, filas encontradas: " . $stmt->rowCount());
  error_log("DEBUG test_data_provider_safe.php - Key data encontrada: " . json_encode($keyData ? ['found' => true, 'provider' => $keyData['provider_label'], 'slug' => $keyData['slug']] : ['found' => false]));
  
  if (!$keyData) {
    error_log("ERROR test_data_provider_safe.php - No se encontró clave para user_id: $userId, provider_id: $providerId");
    json_out(['error' => 'key-not-found', 'message' => 'No se encontró una clave activa para este proveedor'], 404);
  }
  
  // Desencriptar la clave (sistema moderno)
  try {
    error_log("DEBUG test_data_provider_safe.php - Intentando descifrar clave...");
    $apiKey = catai_decrypt($keyData['api_key_enc']);
    error_log("DEBUG test_data_provider_safe.php - Clave descifrada exitosamente, longitud: " . strlen($apiKey));
    
    if (empty($apiKey)) {
      throw new Exception('Clave vacía después del descifrado');
    }
  } catch (Exception $e) {
    error_log("ERROR test_data_provider_safe.php - Error decrypting API key for user $userId, provider $providerId: " . $e->getMessage());
    json_out(['error' => 'decrypt-failed', 'message' => 'Error al acceder a la clave: ' . $e->getMessage()], 500);
  }
  
  // Función genérica para probar cualquier proveedor usando URL de la base de datos
  function testDataProvider($providerSlug, $apiKey, $baseUrl, $testUrl = null) {
    error_log("DEBUG test_data_provider_safe.php - testDataProvider genérico iniciado con slug: '$providerSlug', baseUrl: '$baseUrl', testUrl: '$testUrl'");
    
    // Si no hay URL de test específica, usar lógica genérica básica
    if (empty($testUrl)) {
      error_log("DEBUG test_data_provider_safe.php - No hay URL de test específica, usando test genérico");
      return testGenericProvider($providerSlug, $apiKey, $baseUrl);
    }
    
    // Usar URL de test específica del proveedor
    error_log("DEBUG test_data_provider_safe.php - Usando URL de test específica: $testUrl");
    return testWithCustomUrl($testUrl, $apiKey, $providerSlug);
  }
  
  // Test genérico para proveedores sin URL específica
  function testGenericProvider($providerSlug, $apiKey, $baseUrl) {
    error_log("DEBUG test_data_provider_safe.php - testGenericProvider para: $providerSlug");
    
    // Lógica genérica basada en el tipo de autenticación
    if (empty($baseUrl)) {
      return ['success' => false, 'message' => 'No hay URL base configurada para este proveedor'];
    }
    
    // Test básico de conectividad a la URL base
    $ch = curl_init($baseUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_TIMEOUT        => 8,
      CURLOPT_NOBODY         => true, // Solo HEAD request
    ]);
    
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    error_log("DEBUG test_data_provider_safe.php - Test genérico - HTTP Code: $code, Error: $err");
    
    if ($result === false) {
      return ['success' => false, 'message' => 'Error de conexión: ' . $err];
    }
    
    if ($code >= 200 && $code < 400) {
      return ['success' => true, 'message' => 'Conexión exitosa a ' . $baseUrl];
    } else {
      return ['success' => false, 'message' => 'Error HTTP ' . $code . ' al conectar a ' . $baseUrl];
    }
  }
  
  // Test usando URL específica del proveedor (adaptado del código original exitoso)
  function testWithCustomUrl($testUrl, $apiKey, $providerSlug) {
    error_log("DEBUG test_data_provider_safe.php - testWithCustomUrl iniciado");
    
    error_log("DEBUG test_data_provider_safe.php - API Key recibida: '" . substr($apiKey, 0, 8) . "...' (longitud: " . strlen($apiKey) . ")");
    error_log("DEBUG test_data_provider_safe.php - URL original: $testUrl");
    
    // Reemplazar placeholders en la URL (múltiples formatos)
    $url = str_replace('{API_KEY}', rawurlencode($apiKey), $testUrl);
    $url = str_replace('{api_key}', rawurlencode($apiKey), $url);
    $url = str_replace('{token}', rawurlencode($apiKey), $url);
    $url = str_replace('{apikey}', rawurlencode($apiKey), $url);
    
    error_log("DEBUG test_data_provider_safe.php - URL después de placeholders: $url");
    
    // SIMPLE: Solo concatenar la API key al final de la URL
    // La URL viene como: https://api.polygon.io/v3/reference/tickers?limit=1&apiKey=
    // Y debe quedar: https://api.polygon.io/v3/reference/tickers?limit=1&apiKey=bZK3P1MAMpncmphntpjZPu9zvp2ChyCZ
    error_log("DEBUG test_data_provider_safe.php - Concatenando API key al final de la URL");
    $url = $url . rawurlencode($apiKey);
    
    error_log("DEBUG test_data_provider_safe.php - URL final para test: $url");
    
    // Usar la misma función cURL exitosa del código original
    $curl = function (string $url, array $opt = []) {
      $ch = curl_init($url);
      curl_setopt_array($ch, $opt + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 8,
      ]);
      $body = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err  = curl_error($ch);
      curl_close($ch);
      if ($body === false) return [0, $err, ''];
      return [$code, null, (string)$body];
    };
    
    // Ejecutar test usando la función cURL exitosa
    [$code, $err, $body] = $curl($url);
    
    error_log("DEBUG test_data_provider_safe.php - Test personalizado - HTTP Code: $code, Error: " . ($err ?: 'None'));
    error_log("DEBUG test_data_provider_safe.php - Test personalizado - Response: " . substr($body, 0, 200));
    
    if ($err) {
      return ['success' => false, 'message' => 'Error de conexión: ' . $err];
    }
    
    if ($code >= 200 && $code < 300) {
      // Analizar respuesta específica por proveedor (basado en código original exitoso)
      $bodyLower = strtolower($body);
      
      // Detectar errores específicos de Polygon (como en el código original)
      if (strpos($bodyLower, '"error"') !== false) {
        return ['success' => false, 'message' => 'Error de API: ' . substr($body, 0, 160)];
      }
      
      // Detectar éxito de Polygon (status: OK)
      if (strpos($bodyLower, '"status":"ok"') !== false || 
          strpos($bodyLower, '"status":"ok"') !== false) {
        return ['success' => true, 'message' => 'Conexión exitosa'];
      }
      
      // Detectar errores comunes de API
      if (strpos($bodyLower, 'invalid') !== false || 
          strpos($bodyLower, 'unauthorized') !== false ||
          strpos($bodyLower, 'forbidden') !== false ||
          strpos($bodyLower, 'authentication') !== false) {
        return ['success' => false, 'message' => 'API Key inválida o no autorizada'];
      }
      
      if (strpos($bodyLower, 'rate limit') !== false || 
          strpos($bodyLower, 'quota') !== false ||
          strpos($bodyLower, 'limit exceeded') !== false) {
        return ['success' => true, 'message' => 'API Key válida (límite de rate alcanzado)'];
      }
      
      // Si llegamos aquí y es HTTP 200, probablemente es exitoso
      return ['success' => true, 'message' => 'Conexión exitosa'];
    } else {
      $detail = $err ?: "HTTP $code: " . substr($body, 0, 160);
      return ['success' => false, 'message' => $detail];
    }
  }
  
  
  
  // Probar la conexión
  error_log("DEBUG test_data_provider_safe.php - Iniciando test para proveedor: " . $keyData['slug']);
  error_log("DEBUG test_data_provider_safe.php - URL de test disponible: " . ($keyData['url_request'] ?? 'Ninguna'));
  
  try {
    $testResult = testDataProvider($keyData['slug'], $apiKey, $keyData['base_url'], $keyData['url_request']);
    error_log("DEBUG test_data_provider_safe.php - Resultado del test: " . json_encode($testResult));
  } catch (Exception $e) {
    error_log("ERROR test_data_provider_safe.php - Excepción en testDataProvider: " . $e->getMessage());
    error_log("ERROR test_data_provider_safe.php - Stack trace: " . $e->getTraceAsString());
    $testResult = ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
  } catch (Error $e) {
    error_log("ERROR test_data_provider_safe.php - Error fatal en testDataProvider: " . $e->getMessage());
    error_log("ERROR test_data_provider_safe.php - Stack trace: " . $e->getTraceAsString());
    $testResult = ['success' => false, 'message' => 'Error fatal: ' . $e->getMessage()];
  }
  
  // Actualizar contador de errores y última conexión
  if ($testResult['success']) {
    $updateStmt = $pdo->prepare('
      UPDATE user_data_api_keys 
      SET error_count = 0, 
          last_used_at = NOW(), 
          updated_at = NOW()
      WHERE user_id = ? AND provider_id = ? AND origin = "byok"
    ');
    $updateStmt->execute([$userId, $providerId]);
  } else {
    $updateStmt = $pdo->prepare('
      UPDATE user_data_api_keys 
      SET error_count = error_count + 1, 
          updated_at = NOW()
      WHERE user_id = ? AND provider_id = ? AND origin = "byok"
    ');
    $updateStmt->execute([$userId, $providerId]);
  }
  
  $response = [
    'ok' => $testResult['success'],
    'message' => $testResult['message'],
    'provider' => [
      'id' => $providerId,
      'slug' => $keyData['slug'],
      'label' => $keyData['provider_label'],
      'environment' => $keyData['environment']
    ],
    'test_details' => [
      'error_count' => $testResult['success'] ? 0 : $keyData['error_count'] + 1,
      'tested_at' => date('Y-m-d H:i:s')
    ]
  ];
  
  error_log("DEBUG test_data_provider_safe.php - Respuesta final: " . json_encode($response));
  json_out($response);
  
} catch (Exception $e) {
  error_log("Error en test_data_provider_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
