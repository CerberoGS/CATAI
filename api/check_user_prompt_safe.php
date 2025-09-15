<?php
// /bolsa/api/check_user_prompt_safe.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    // 1) Autenticación
    $user = require_user();
    
    $pdo = db();
    
    // 2) Obtener prompt personalizado del usuario
    $stmt = $pdo->prepare('SELECT ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $customPrompt = $userSettings['ai_prompt_ext_conten_file'] ?? null;
    $hasCustomPrompt = !empty($customPrompt);
    
    // 3) Obtener prompt predeterminado
    $defaultPrompt = $CONFIG['AI_PROMPT_EXTRACT_DEFAULT'] ?? 'Prompt predeterminado no configurado';
    
    // 4) Determinar cuál se está usando
    $promptUsed = $hasCustomPrompt ? $customPrompt : $defaultPrompt;
    $promptSource = $hasCustomPrompt ? 'personalizado' : 'predeterminado';
    
    json_out([
        'ok' => true,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email']
        ],
        'prompt_analysis' => [
            'has_custom_prompt' => $hasCustomPrompt,
            'custom_prompt_length' => $hasCustomPrompt ? strlen($customPrompt) : 0,
            'default_prompt_length' => strlen($defaultPrompt),
            'prompt_source' => $promptSource,
            'prompt_used' => $promptUsed
        ],
        'prompts' => [
            'custom' => $customPrompt,
            'default' => $defaultPrompt
        ],
        'message' => 'Análisis de prompts completado'
    ]);
    
} catch (Throwable $e) {
    error_log("check_user_prompt_safe.php error: " . $e->getMessage());
    json_out(['error' => 'check-failed', 'detail' => $e->getMessage()], 500);
}
