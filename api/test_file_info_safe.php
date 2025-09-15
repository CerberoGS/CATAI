<?php
// /bolsa/api/test_file_info_safe.php
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
    
    // 3) Obtener información del archivo
    $stmt = $pdo->prepare('SELECT id, original_filename, stored_filename, file_type, file_size FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_out(['error' => 'file-not-found'], 404);
    }
    
    // 4) Verificar archivo físico
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
    $fileExists = file_exists($filePath);
    $fileSize = $fileExists ? filesize($filePath) : 0;
    
    // 5) Respuesta con estructura estándar para simulación
    json_out([
        'ok' => true,
        'test' => 'file-info',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email']
        ],
        'file_db' => [
            'id' => $kf['id'],
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_type' => $kf['file_type'],
            'file_size' => $kf['file_size'],
            'file_size_mb' => round($kf['file_size'] / 1024 / 1024, 2)
        ],
        'file_physical' => [
            'path' => $filePath,
            'exists' => $fileExists,
            'size_bytes' => $fileSize,
            'size_mb' => round($fileSize / 1024 / 1024, 2)
        ],
        'message' => 'Información de archivo obtenida correctamente'
    ]);
    
} catch (Throwable $e) {
    error_log("test_file_info_safe.php error: " . $e->getMessage());
    json_out(['error' => 'test-failed', 'detail' => $e->getMessage()], 500);
}
