<?php
// /bolsa/api/test_data_structures_safe.php
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
    
    $pdo = db();
    
    // 3) Test estructura de configuración
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model, ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 4) Test estructura de archivo
    $stmt = $pdo->prepare('SELECT id, original_filename, stored_filename, file_type, file_size FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_out(['error' => 'file-not-found'], 404);
    }
    
    // 5) Test estructura de API keys
    $stmt = $pdo->prepare('SELECT provider, api_key_enc FROM user_api_keys WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $keyStatus = [];
    foreach ($keys as $key) {
        $keyStatus[$key['provider']] = [
            'has_key' => !empty($key['api_key_enc']),
            'key_length' => strlen($key['api_key_enc'] ?? '')
        ];
    }
    
    // 6) Respuesta con todas las estructuras
    json_out([
        'ok' => true,
        'test' => 'data-structures',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email']
        ],
        'file_id' => $fileId,
        'structures' => [
            'settings' => [
                'structure' => 'user_settings table',
                'data' => $settings,
                'has_ai_provider' => !empty($settings['ai_provider']),
                'has_ai_model' => !empty($settings['ai_model']),
                'has_custom_prompt' => !empty($settings['ai_prompt_ext_conten_file'])
            ],
            'file_db' => [
                'structure' => 'knowledge_files table',
                'data' => $kf,
                'has_original_filename' => !empty($kf['original_filename']),
                'has_stored_filename' => !empty($kf['stored_filename']),
                'has_file_type' => !empty($kf['file_type'])
            ],
            'api_keys' => [
                'structure' => 'user_api_keys table',
                'data' => $keyStatus,
                'providers_with_keys' => array_keys(array_filter($keyStatus, fn($k) => $k['has_key']))
            ]
        ],
        'message' => 'Estructuras de datos verificadas correctamente'
    ]);
    
} catch (Throwable $e) {
    error_log("test_data_structures_safe.php error: " . $e->getMessage());
    json_out(['error' => 'test-failed', 'detail' => $e->getMessage()], 500);
}
