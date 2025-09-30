<?php
// /catai/api/lib/ProviderKeyTest.php
declare(strict_types=1);

/**
 * Helper genérico para probar API keys de cualquier proveedor
 * Usa configuración declarativa desde config_json.test
 */

function mask_key(string $k): string {
  if (strlen($k) <= 10) return '****';
  return substr($k, 0, 3) . '****' . substr($k, -6);
}

function render_vars(string $s, array $vars): string {
  return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function($m) use ($vars) {
    return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
  }, $s);
}

/**
 * Ejecuta prueba declarativa según config_json.test
 * 
 * @param array $providerRow Fila de *_providers (incluye url_request y config_json)
 * @param string $apiKey Clave real del usuario
 * @param array $projectFields Variables extra requeridas por el proveedor
 * @param callable $curl Función cURL con firma: fn(string $url, array $opts): array [$code, $err, $body]
 * @return array Resultado de la prueba
 */
function testProviderKey(array $providerRow, string $apiKey, array $projectFields, callable $curl): array {
  $startTime = microtime(true);
  
  // Logging inicial
  error_log("🧪 ProviderKeyTest iniciado para: " . ($providerRow['name'] ?? 'Unknown'));
  error_log("🔍 API Key enmascarada: " . mask_key($apiKey));
  error_log("📋 Project fields: " . json_encode($projectFields));
  
  // Parsear configuración
  $cfg = json_decode($providerRow['config_json'] ?? '{}', true);
  $t = $cfg['test'] ?? [];
  
  error_log("⚙️ Config test encontrada: " . json_encode($t));
  
  // Validar configuración requerida
  if (empty($t)) {
    error_log("❌ No hay configuración 'test' en config_json");
    return [
      'ok' => false,
      'reason' => 'No hay configuración de prueba disponible',
      'http_code' => null,
      'body_snippet' => '',
      'debug' => 'config_json.test no encontrado'
    ];
  }
  
  // Preparar variables
  $vars = array_merge(
    ['API_KEY' => $apiKey], 
    $t['defaults'] ?? [], 
    $projectFields
  );
  
  error_log("🔧 Variables preparadas: " . json_encode(array_keys($vars)));
  
  // Construir URL
  error_log("🔍 DEBUG URL - url_override: " . ($t['url_override'] ?? 'NOT_SET'));
  error_log("🔍 DEBUG URL - url_request: " . ($providerRow['url_request'] ?? 'NOT_SET'));
  
  $url = $t['url_override'] ?? ($providerRow['url_request'] ?? '');
  error_log("🔍 DEBUG URL - URL inicial: '$url'");
  error_log("🔍 DEBUG URL - URL empty: " . (empty($url) ? 'YES' : 'NO'));
  
  if (empty($url)) {
    error_log("❌ No hay URL configurada");
    return [
      'ok' => false,
      'reason' => 'No hay URL de prueba configurada',
      'http_code' => null,
      'body_snippet' => '',
      'debug' => 'url_request y url_override están vacíos'
    ];
  }
  
  error_log("🔍 DEBUG URL - Antes de render_vars: '$url'");
  $url = render_vars($url, $vars);
  error_log("🔍 DEBUG URL - Después de render_vars: '$url'");
  error_log("🔍 DEBUG URL - URL final length: " . strlen($url));
  
  // Construir query parameters
  if (!empty($t['query'])) {
    $pairs = [];
    foreach ($t['query'] as $q) {
      $pairs[] = urlencode($q['name']) . '=' . urlencode(render_vars($q['value'], $vars));
    }
    if ($pairs) {
      $url .= (strpos($url, '?') === false ? '?' : '&') . implode('&', $pairs);
    }
    error_log("🔗 Query params agregados: " . implode('&', $pairs));
  }
  
  // Construir headers
  $headers = [];
  if (!empty($t['headers'])) {
    foreach ($t['headers'] as $h) {
      $headers[] = $h['name'] . ': ' . render_vars($h['value'], $vars);
    }
    error_log("📋 Headers preparados: " . json_encode($headers));
  }
  
  // Normalizar URL (quita CR/LF/espacios invisibles)
  $url = str_replace(["\r", "\n"], '', $url);
  $url = trim($url);
  
  error_log("🔧 URL normalizada: '$url'");
  error_log("🔧 URL length después de normalizar: " . strlen($url));
  
  // Validación defensiva
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    error_log("❌ URL inválida tras normalizar: '$url'");
    return [
      'ok' => false,
      'reason' => 'URL inválida tras normalizar',
      'http_code' => 0,
      'body_snippet' => null,
      'debug' => 'URL no pasa validación FILTER_VALIDATE_URL'
    ];
  }
  
  error_log("✅ URL válida según FILTER_VALIDATE_URL");

  // Configurar método y body
  $method = strtoupper($t['method'] ?? 'GET');
  $opts = [
    CURLOPT_URL => $url,  // <-- 👈 IMPRESCINDIBLE: URL en las opciones de cURL
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'CerberoKeyTester/1.1',
  ];
  
  if ($method !== 'GET') {
    $opts[CURLOPT_CUSTOMREQUEST] = $method;
    if (!empty($t['body'])) {
      $body = render_vars($t['body'], $vars);
      $opts[CURLOPT_POSTFIELDS] = $body;
      error_log("📦 Body preparado: " . substr($body, 0, 200) . (strlen($body) > 200 ? '...' : ''));
    }
  }
  
  error_log("🚀 Ejecutando petición $method a: $url");
  error_log("🚀 DEBUG - URL que se pasa a cURL: '$url'");
  error_log("🚀 DEBUG - URL length: " . strlen($url));
  error_log("🚀 DEBUG - URL empty: " . (empty($url) ? 'YES' : 'NO'));
  
  // Ejecutar petición
  [$code, $err, $body] = $curl($url, $opts);
  $duration = round((microtime(true) - $startTime) * 1000, 2);
  
  error_log("⏱️ Petición completada en {$duration}ms");
  error_log("📊 Código HTTP: $code");
  error_log("❌ Error cURL: " . ($err ?: 'Ninguno'));
  error_log("📄 Body recibido (primeros 200 chars): " . substr((string)$body, 0, 200));
  
  // Manejar error de cURL
  if ($err) {
    error_log("❌ Error cURL: $err");
    return [
      'ok' => false,
      'reason' => 'Error de conexión: ' . $err,
      'http_code' => $code,
      'body_snippet' => substr((string)$body, 0, 800),
      'debug' => "cURL error: $err",
      'duration_ms' => $duration
    ];
  }
  
  // Validar status HTTP
  $expected = $t['expected_status'] ?? 200;
  $okStatus = is_array($expected) ? in_array($code, $expected, true) : ((int)$expected === (int)$code);
  
  error_log("🎯 Status esperado: " . (is_array($expected) ? implode(',', $expected) : $expected));
  error_log("✅ Status válido: " . ($okStatus ? 'SÍ' : 'NO'));
  
  if (!$okStatus) {
    error_log("❌ Status HTTP no válido: $code (esperado: $expected)");
    return [
      'ok' => false,
      'reason' => "HTTP $code (esperado: " . (is_array($expected) ? implode(',', $expected) : $expected) . ")",
      'http_code' => $code,
      'body_snippet' => substr((string)$body, 0, 800),
      'debug' => "Status HTTP $code no coincide con esperado",
      'duration_ms' => $duration
    ];
  }
  
  // Validar contenido JSON
  if (!empty($t['ok_json_path']) && array_key_exists('ok_json_expected', $t)) {
    error_log("🔍 Validando JSON path: " . $t['ok_json_path'] . " = " . $t['ok_json_expected']);
    
    $json = json_decode($body, true);
    if (!is_array($json)) {
      error_log("❌ Respuesta no es JSON válido");
      return [
        'ok' => false,
        'reason' => 'Respuesta no es JSON válido',
        'http_code' => $code,
        'body_snippet' => substr((string)$body, 0, 800),
        'debug' => 'JSON inválido',
        'duration_ms' => $duration
      ];
    }
    
    $val = $json;
    foreach (explode('.', $t['ok_json_path']) as $k) {
      if (!is_array($val) || !array_key_exists($k, $val)) {
        error_log("❌ Path JSON no encontrado: " . $t['ok_json_path']);
        return [
          'ok' => false,
          'reason' => 'Path JSON no encontrado: ' . $t['ok_json_path'],
          'http_code' => $code,
          'body_snippet' => substr((string)$body, 0, 800),
          'debug' => "Path {$t['ok_json_path']} no existe en JSON",
          'duration_ms' => $duration
        ];
      }
      $val = $val[$k];
    }
    
    if ((string)$val !== (string)$t['ok_json_expected']) {
      error_log("❌ Valor JSON no coincide: " . $val . " (esperado: " . $t['ok_json_expected'] . ")");
      return [
        'ok' => false,
        'reason' => "Valor JSON incorrecto: {$t['ok_json_path']} = '$val' (esperado: '{$t['ok_json_expected']}')",
        'http_code' => $code,
        'body_snippet' => substr((string)$body, 0, 800),
        'debug' => "JSON validation failed: {$t['ok_json_path']}",
        'duration_ms' => $duration
      ];
    }
    
    error_log("✅ Validación JSON exitosa");
  }
  
  // Validar con regex
  if (!empty($t['success_regex'])) {
    error_log("🔍 Validando regex: " . $t['success_regex']);
    if (!preg_match('/' . $t['success_regex'] . '/i', (string)$body)) {
      error_log("❌ Regex no coincide");
      return [
        'ok' => false,
        'reason' => 'Regex de éxito no coincide',
        'http_code' => $code,
        'body_snippet' => substr((string)$body, 0, 800),
        'debug' => "Regex '{$t['success_regex']}' no coincide",
        'duration_ms' => $duration
      ];
    }
    error_log("✅ Validación regex exitosa");
  }
  
  error_log("🎉 Prueba exitosa completada");
  
  return [
    'ok' => true,
    'http_code' => $code,
    'reason' => null,
    'body_snippet' => substr((string)$body, 0, 800),
    'debug' => 'Prueba exitosa',
    'duration_ms' => $duration
  ];
}
