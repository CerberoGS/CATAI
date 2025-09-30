<?php declare(strict_types=1);

/**
 * Test para debuggear el problema del provider_id
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
    
    // 1. Verificar configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model, default_provider_id, default_model_id FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $settings['ai_provider'] ?? 'openai';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o-mini';
    $defaultProviderId = $settings['default_provider_id'] ?? null;
    $defaultModelId = $settings['default_model_id'] ?? null;
    
    // 2. Buscar el proveedor usando default_provider_id o fallback a slug
    if ($defaultProviderId) {
        $stmt = $pdo->prepare('SELECT id, slug, ops_json FROM ai_providers WHERE id = ? AND status = "active"');
        $stmt->execute([$defaultProviderId]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Fallback al método anterior
        $stmt = $pdo->prepare('SELECT id, slug, ops_json FROM ai_providers WHERE slug = ? AND status = "active"');
        $stmt->execute([$aiProvider]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // 3. Verificar si existe
    if (!$provider) {
        json_error('provider_not_found', 404, "Proveedor '$aiProvider' no encontrado");
    }
    
    // 4. Verificar ops_json
    $opsJson = json_decode($provider['ops_json'], true);
    if (!$opsJson || !isset($opsJson['multi'])) {
        json_error('ops_json_invalid', 400, "ops_json inválido para '$aiProvider'");
    }
    
    // 5. Verificar operación específica
    $opName = 'vs.summarize_from_vs';
    if (!isset($opsJson['multi'][$opName])) {
        json_error('op_not_found', 404, "Operación '$opName' no encontrada en ops_json");
    }
    
    json_out([
        'ok' => true,
        'debug' => [
            'user_id' => $user_id,
            'ai_provider' => $aiProvider,
            'ai_model' => $aiModel,
            'default_provider_id' => $defaultProviderId,
            'default_model_id' => $defaultModelId,
            'provider' => [
                'id' => $provider['id'],
                'slug' => $provider['slug'],
                'has_ops_json' => !empty($provider['ops_json']),
                'ops_json_size' => strlen($provider['ops_json'])
            ],
            'operation' => [
                'name' => $opName,
                'exists' => isset($opsJson['multi'][$opName]),
                'config' => $opsJson['multi'][$opName] ?? null
            ]
        ]
    ]);

} catch (Exception $e) {
    error_log("Error en test_provider_debug: " . $e->getMessage());
    json_error('error', 500, $e->getMessage());
}
