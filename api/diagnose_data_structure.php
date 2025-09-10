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

try {
    $pdo = db();
    
    // Diagnóstico completo de la estructura de datos
    $diagnosis = [
        'user_id' => $user_id,
        'knowledge_base_records' => [],
        'knowledge_files_records' => [],
        'data_consistency' => [],
        'recommendations' => []
    ];
    
    // 1. Verificar registros en knowledge_base para este usuario
    $stmt = $pdo->prepare("
        SELECT id, title, source_file, created_by, created_at 
        FROM knowledge_base 
        WHERE created_by = ? 
        ORDER BY id DESC
    ");
    $stmt->execute([$user_id]);
    $diagnosis['knowledge_base_records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Verificar registros en knowledge_files para este usuario
    $stmt = $pdo->prepare("
        SELECT id, original_filename, stored_filename, user_id, created_at 
        FROM knowledge_files 
        WHERE user_id = ? 
        ORDER BY id DESC
    ");
    $stmt->execute([$user_id]);
    $diagnosis['knowledge_files_records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Verificar consistencia entre tablas
    $stmt = $pdo->prepare("
        SELECT 
            kb.id as kb_id,
            kb.title,
            kb.source_file as kb_source_file,
            kf.id as kf_id,
            kf.original_filename,
            kf.stored_filename,
            CASE 
                WHEN kf.id IS NULL THEN 'MISSING_FILE_RECORD'
                WHEN kb.source_file != kf.id THEN 'MISMATCH_IDS'
                ELSE 'CONSISTENT'
            END as consistency_status
        FROM knowledge_base kb
        LEFT JOIN knowledge_files kf ON kb.source_file = kf.id
        WHERE kb.created_by = ?
        ORDER BY kb.id DESC
    ");
    $stmt->execute([$user_id]);
    $diagnosis['data_consistency'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Verificar archivos físicos
    $upload_dir = __DIR__ . '/uploads/knowledge/' . $user_id;
    $physical_files = [];
    
    if (is_dir($upload_dir)) {
        $files = scandir($upload_dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $file_path = $upload_dir . '/' . $file;
                $physical_files[] = [
                    'filename' => $file,
                    'size' => filesize($file_path),
                    'modified' => date('Y-m-d H:i:s', filemtime($file_path))
                ];
            }
        }
    }
    
    $diagnosis['physical_files'] = $physical_files;
    
    // 5. Generar recomendaciones
    $recommendations = [];
    
    if (empty($diagnosis['knowledge_base_records'])) {
        $recommendations[] = 'No hay registros en knowledge_base para este usuario';
    }
    
    if (empty($diagnosis['knowledge_files_records'])) {
        $recommendations[] = 'No hay registros en knowledge_files para este usuario';
    }
    
    foreach ($diagnosis['data_consistency'] as $record) {
        if ($record['consistency_status'] === 'MISSING_FILE_RECORD') {
            $recommendations[] = "knowledge_base ID {$record['kb_id']} no tiene registro correspondiente en knowledge_files";
        } elseif ($record['consistency_status'] === 'MISMATCH_IDS') {
            $recommendations[] = "Mismatch entre knowledge_base.source_file ({$record['kb_source_file']}) y knowledge_files.id ({$record['kf_id']})";
        }
    }
    
    $diagnosis['recommendations'] = $recommendations;
    
    // 6. Sugerir ID válido para testing
    $valid_ids = array_column($diagnosis['knowledge_base_records'], 'id');
    $diagnosis['suggested_test_id'] = !empty($valid_ids) ? $valid_ids[0] : null;
    
    json_out($diagnosis);
    
} catch (Exception $e) {
    error_log("Error en diagnose_data_structure.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
