<?php
// /bolsa/api/test_ai_extract_simple_safe.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    // 1) Autenticación
    $user = require_user();
    
    // 2) Obtener parámetros
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $fileId = (int)($input['file_id'] ?? 0);
    
    if (!$fileId) {
        json_out(['error' => 'file-id-required'], 400);
    }
    
    // 3) Obtener configuración del usuario
    $pdo = db();
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model, ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_out(['error' => 'user-settings-not-found'], 404);
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    $aiModel = $settings['ai_model'] ?? '';
    $customPrompt = $settings['ai_prompt_ext_conten_file'] ?? null;
    
    // 4) Obtener prompt por defecto
    $defaultPrompt = $CONFIG['AI_PROMPT_EXTRACT_DEFAULT'] ?? 'Analiza este documento y proporciona un resumen estructurado.';
    
    // 5) Respuesta de prueba
    json_out([
        'ok' => true,
        'test' => 'ai-extract-simple',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email']
        ],
        'file_id' => $fileId,
        'settings' => [
            'ai_provider' => $aiProvider,
            'ai_model' => $aiModel,
            'has_custom_prompt' => !empty($customPrompt),
            'prompt_length' => strlen($customPrompt ?? ''),
            'default_prompt_length' => strlen($defaultPrompt)
        ],
        'prompt_used' => !empty($customPrompt) ? 'custom' : 'default',
        'message' => 'Test básico de configuración de IA exitoso'
    ]);
    
} catch (Throwable $e) {
    error_log("test_ai_extract_simple_safe.php error: " . $e->getMessage());
    json_out(['error' => 'test-failed', 'detail' => $e->getMessage()], 500);
}
