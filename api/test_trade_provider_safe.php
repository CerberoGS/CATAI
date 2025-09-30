<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Crypto_safe.php';

json_header();

try {
    $u = require_user();
    $userId = (int)($u['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error'=>'invalid-user'], 401);
    }
    
    $in = json_input(true) ?: [];
    $providerId = (int)($in['provider_id'] ?? 0);
    
    if ($providerId <= 0) {
        json_out(['error' => 'invalid-provider', 'message' => 'ID de proveedor inválido'], 400);
    }

    $pdo = db();
    
    // Obtener información del proveedor y la clave del usuario
    $stmt = $pdo->prepare('
        SELECT 
            dak.api_key_enc,
            dak.key_ciphertext,
            dak.environment,
            dak.error_count,
            tp.slug,
            tp.name as provider_label,
            tp.url_request
        FROM user_trade_api_keys dak
        JOIN trade_providers tp ON dak.provider_id = tp.id
        WHERE dak.user_id = ? AND dak.provider_id = ? AND dak.origin = "byok" AND dak.status = "active"
    ');
    $stmt->execute([$userId, $providerId]);
    $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$keyData) {
        json_out(['error' => 'key-not-found', 'message' => 'No se encontró una clave activa para este proveedor'], 404);
    }
    
    // Descifrar la clave
    $apiKey = catai_decrypt($keyData['api_key_enc']);
    
    if (!$apiKey) {
        json_out(['error' => 'decrypt-failed', 'message' => 'Error al descifrar la clave'], 500);
    }
    
    // Obtener URL de prueba desde url_request
    $testUrl = $keyData['url_request'];
    
    if (!$testUrl) {
        json_out(['error' => 'no-test-url', 'message' => 'No hay URL de prueba configurada para este proveedor'], 400);
    }
    
    // Reemplazar placeholders en la URL
    $testUrl = str_replace(['{API_KEY}', '{api_key}', '{token}', '{apikey}'], $apiKey, $testUrl);
    
    // Si no hay placeholders, concatenar la API key
    if (!str_contains($testUrl, $apiKey)) {
        $testUrl .= (str_contains($testUrl, '?') ? '&' : '?') . 'apiKey=' . $apiKey;
    }
    
    // Realizar la prueba HTTP
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        json_out([
            'ok' => false,
            'error' => 'curl-error',
            'message' => 'Error de conexión: ' . $error,
            'provider' => $keyData['provider_label'],
            'url' => $testUrl
        ]);
    }
    
    // Analizar respuesta
    $success = $httpCode >= 200 && $httpCode < 300;
    $responseData = null;
    
    if ($response) {
        $responseData = json_decode($response, true);
    }
    
    // Determinar si la prueba fue exitosa
    $testPassed = $success;
    
    // Análisis específico por proveedor (si es necesario)
    if ($responseData) {
        switch (strtolower($keyData['slug'])) {
            case 'alpaca':
                $testPassed = isset($responseData['account']) || isset($responseData['status']);
                break;
            case 'interactive-brokers':
                $testPassed = isset($responseData['accounts']) || isset($responseData['positions']);
                break;
            case 'td-ameritrade':
                $testPassed = isset($responseData['securitiesAccount']) || isset($responseData['accounts']);
                break;
            default:
                // Para otros proveedores, asumir éxito si HTTP 200-299
                $testPassed = $success;
        }
    }
    
    json_out([
        'ok' => true,
        'success' => $testPassed,
        'provider' => $keyData['provider_label'],
        'slug' => $keyData['slug'],
        'http_code' => $httpCode,
        'response_preview' => $responseData ? array_slice($responseData, 0, 3, true) : null,
        'test_url' => $testUrl,
        'message' => $testPassed ? 'Prueba exitosa' : 'Prueba falló'
    ]);
    
} catch (Throwable $e) {
    error_log("Error en test_trade_provider_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
