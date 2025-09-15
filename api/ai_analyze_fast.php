<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no autenticado', 401);
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);
$prompt = $input['prompt'] ?? '';
$provider = $input['provider'] ?? 'auto';
$model = $input['model'] ?? '';
$systemPrompt = $input['systemPrompt'] ?? '';

if (empty($prompt)) {
    json_error('Prompt requerido', 400);
}

try {
    // Llamar directamente al proveedor de IA sin contexto adicional
    $aiResponse = callAIProviderDirect($provider, $model, $prompt, $systemPrompt);
    
    // Respuesta rápida
    json_out([
        'text' => $aiResponse['text'],
        'provider' => $aiResponse['provider'],
        'model' => $aiResponse['model'],
        'context_sources' => [],
        'context_length' => 0,
        'enriched_prompt_length' => strlen($prompt),
        'optimization' => 'fast_mode'
    ]);
    
} catch (Exception $e) {
    error_log("Error en ai_analyze_fast.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

// Función para llamar directamente al proveedor de IA
function callAIProviderDirect($provider, $model, $prompt, $systemPrompt) {
    // Implementación simplificada - usar el endpoint existente
    $data = [
        'provider' => $provider,
        'model' => $model,
        'prompt' => $prompt,
        'systemPrompt' => $systemPrompt
    ];
    
    // Llamada interna al endpoint de IA usando URL portable
    $baseUrl = getApiUrl('ai_analyze.php');
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout más corto
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Para desarrollo
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    } else {
        throw new Exception('Error en proveedor de IA: ' . $httpCode);
    }
}
?>
