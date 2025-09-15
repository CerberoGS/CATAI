<?php
// /bolsa/api/test_file_access_safe.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
    }
    
    $input = json_input();
    $fileId = (int)($input['file_id'] ?? 0);
    
    if ($fileId <= 0) {
        json_out(['error' => 'file_id-required'], 400);
    }
    
    // 3) Obtener conexión a la base de datos
    $pdo = db();
    
    // 4) Verificar que el archivo existe en knowledge_files
    $stmt = $pdo->prepare('SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $userId]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_out(['error' => 'file-not-found', 'detail' => "Archivo ID $fileId no encontrado para usuario $userId"], 404);
    }
    
    // 2) Construir path del archivo y verificar existencia
    $filePath = __DIR__ . '/uploads/knowledge/' . $userId . '/' . $kf['stored_filename'];
    $fileExists = file_exists($filePath);
    $fileSize = $fileExists ? filesize($filePath) : 0;
    
    // 3) Leer una muestra del contenido solo si el archivo existe
    $fileContent = '';
    $fileContentPreview = '';
    if ($fileExists) {
        try {
            $fileContent = file_get_contents($filePath);
            $fileContentPreview = substr($fileContent, 0, 500);
            $fileContentPreview = preg_replace('/[^\x20-\x7E]/', '', $fileContentPreview); // Solo ASCII
        } catch (Exception $e) {
            $fileContentPreview = 'Error leyendo archivo: ' . $e->getMessage();
        }
    }
    
    // 4) Detectar tipo de archivo desde la base de datos (más eficiente)
    $fileTypeFromDB = $kf['file_type'] ?? 'unknown';
    $mimeTypeFromDB = $kf['mime_type'] ?? 'unknown';
    
    // 5) Detectar tipo de archivo desde extensión como fallback
    $fileExtension = strtolower(pathinfo($kf['original_filename'], PATHINFO_EXTENSION));
    $detectedType = match($fileExtension) {
        'pdf' => 'pdf',
        'txt', 'text' => 'text',
        'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp' => 'image',
        'doc', 'docx' => 'document',
        'xls', 'xlsx' => 'spreadsheet',
        default => 'unknown'
    };
    
    // 4) Obtener configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 5) Obtener estado de API keys
    $stmt = $pdo->prepare('SELECT provider, api_key_enc FROM user_api_keys WHERE user_id = ?');
    $stmt->execute([$userId]);
    $apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $keyStatus = [];
    foreach ($apiKeys as $key) {
        $keyStatus[$key['provider']] = [
            'has_key' => !empty($key['api_key_enc']),
            'key_length' => strlen($key['api_key_enc'] ?? '')
        ];
    }
    
    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => [
            'id' => $userId,
            'email' => $user['email']
        ],
        'file' => [
            'id' => $kf['id'],
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_type' => $fileTypeFromDB,
            'mime_type' => $mimeTypeFromDB,
            'file_size' => $kf['file_size'],
            'file_size_mb' => round($kf['file_size'] / 1024 / 1024, 2),
            'upload_status' => $kf['upload_status'],
            'extraction_status' => $kf['extraction_status'],
            'created_at' => $kf['created_at']
        ],
        'file_physical' => [
            'path' => $filePath,
            'path_construction' => [
                'step_1' => 'Base directory: ' . __DIR__,
                'step_2' => 'Add uploads: ' . __DIR__ . '/uploads/knowledge/',
                'step_3' => 'Add user ID: ' . __DIR__ . '/uploads/knowledge/' . $userId . '/',
                'step_4' => 'Add filename: ' . $filePath,
                'final_path' => $filePath
            ],
            'path_components' => [
                'base_dir' => __DIR__,
                'uploads_dir' => '/uploads/knowledge/',
                'user_dir' => $userId,
                'filename' => $kf['stored_filename']
            ],
            'exists' => $fileExists,
            'size_bytes' => $fileSize,
            'size_mb' => round($fileSize / 1024 / 1024, 2),
            'content_preview' => $fileContentPreview,
            'content_length' => strlen($fileContent)
        ],
        'file_type_detection' => [
            'from_db' => [
                'file_type' => $fileTypeFromDB,
                'mime_type' => $mimeTypeFromDB
            ],
            'from_extension' => [
                'extension' => $fileExtension,
                'detected_type' => $detectedType
            ],
            'recommendation' => $fileTypeFromDB !== 'unknown' ? 'use_db_type' : 'use_extension_type'
        ],
        'user_settings' => [
            'ai_provider' => $settings['ai_provider'] ?? 'auto',
            'ai_model' => $settings['ai_model'] ?? 'gpt-4o'
        ],
        'api_keys' => $keyStatus,
        'ready_for_extraction' => [
            'file_exists' => $fileExists,
            'has_ai_provider' => !empty($settings['ai_provider']),
            'has_api_key' => !empty($keyStatus[$settings['ai_provider'] ?? 'openai']['has_key'] ?? false),
            'can_proceed' => $fileExists && !empty($settings['ai_provider']) && !empty($keyStatus[$settings['ai_provider'] ?? 'openai']['has_key'] ?? false)
        ]
    ], 200);
    
} catch (Throwable $e) {
    json_out(['error' => 'test-failed', 'detail' => $e->getMessage()], 500);
}
