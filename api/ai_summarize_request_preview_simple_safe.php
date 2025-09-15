<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

try {
    if (!ob_get_level()) { ob_start(); }
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    $user = require_user();
    
    // Obtener parámetros de GET/POST
    $knowledgeId = (int)($_GET['knowledge_id'] ?? $_POST['knowledge_id'] ?? 0);
    $fileId = (int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0);
    $provider = trim($_GET['provider'] ?? $_POST['provider'] ?? 'auto');
    $model = trim($_GET['model'] ?? $_POST['model'] ?? '');
    
    $result = [
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user['id'],
        'params' => [
            'knowledge_id' => $knowledgeId,
            'file_id' => $fileId,
            'provider' => $provider,
            'model' => $model
        ]
    ];
    
    // Intentar resolver archivo
    $pdo = db();
    
    // Buscar por file_id primero
    if ($fileId > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
        $stmt->execute([$fileId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['file_resolution'] = 'by_file_id';
    } else {
        $kf = false;
        $result['file_resolution'] = 'no_file_id';
    }
    
    // Si no se encontró por file_id, buscar por knowledge_id
    if (!$kf && $knowledgeId > 0) {
        $stmt = $pdo->prepare('SELECT source_file FROM knowledge_base WHERE id = ? AND created_by = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kb = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($kb && !empty($kb['source_file'])) {
            $stmt2 = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND (original_filename = ? OR stored_filename = ?) ORDER BY created_at DESC LIMIT 1');
            $stmt2->execute([$user['id'], $kb['source_file'], $kb['source_file']]);
            $kf = $stmt2->fetch(PDO::FETCH_ASSOC);
            $result['file_resolution'] = 'by_knowledge_id';
        }
    }
    
    if ($kf) {
        $result['file_found'] = true;
        $result['file_info'] = $kf;
        
        // Verificar archivo físico
        $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
        $result['file_path'] = $filePath;
        $result['file_exists'] = is_file($filePath);
        $result['file_readable'] = is_readable($filePath);
        
        if ($result['file_exists'] && $result['file_readable']) {
            $result['file_size_mb'] = round($kf['file_size'] / (1024*1024), 2);
            $result['success'] = true;
        } else {
            $result['error'] = 'Archivo físico no accesible';
        }
    } else {
        $result['file_found'] = false;
        $result['error'] = 'Archivo no encontrado en base de datos';
    }
    
    // Verificar fugas de salida
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    if ($leak !== '') {
        $result['output_leak'] = $leak;
    }
    
    json_out($result);
    
} catch (Throwable $e) {
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    
    json_error('Error: ' . $e->getMessage(), 500, [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'leak' => $leak
    ]);
}
