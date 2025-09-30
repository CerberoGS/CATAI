<?php declare(strict_types=1);

/**
 * Test directo de runOp para debuggear el problema
 */

require_once 'helpers.php';
require_once 'db.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }

    $pdo = get_pdo();
    
    // 1. Obtener configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model, default_provider_id, default_model_id FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $settings['ai_provider'] ?? 'openai';
    $defaultProviderId = $settings['default_provider_id'] ?? null;
    
    // 2. Determinar el provider_id a usar
    $providerId = null;
    if ($defaultProviderId) {
        $providerId = $defaultProviderId;
        error_log("Usando default_provider_id: $providerId");
    } else {
        // Fallback: buscar por slug
        $stmt = $pdo->prepare('SELECT id FROM ai_providers WHERE slug = ? AND status = "active"');
        $stmt->execute([$aiProvider]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($provider) {
            $providerId = $provider['id'];
            error_log("Usando provider_id por slug '$aiProvider': $providerId");
        }
    }
    
    if (!$providerId) {
        json_error('no_provider_id', 404, "No se pudo determinar provider_id");
    }
    
    // 3. Preparar llamada directa a runOp
    $operation = 'vs.summarize_from_vs';
    $params = [
        'VS_ID' => 'vs_test',
        'PROMPT' => 'Test prompt'
    ];
    
    $payload = [
        'provider_id' => (int)$providerId,
        'op' => $operation,
        'params' => $params
    ];
    
    // 4. Construir URL
    require_once 'config.php';
    $apiUrl = $CONFIG['API_BASE_URL'] . '/run_op_safe.php';
    
    // 5. Hacer llamada HTTP
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (bearer_token() ?? '')
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    json_out([
        'ok' => true,
        'debug' => [
            'user_id' => $user_id,
            'ai_provider' => $aiProvider,
            'default_provider_id' => $defaultProviderId,
            'final_provider_id' => $providerId,
            'operation' => $operation,
            'params' => $params,
            'url' => $apiUrl,
            'http_code' => $httpCode,
            'response' => $response,
            'payload' => $payload
        ]
    ]);

} catch (Exception $e) {
    error_log("Error en test_runop_direct: " . $e->getMessage());
    json_error('error', 500, $e->getMessage());
}
