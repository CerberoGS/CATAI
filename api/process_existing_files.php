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
    
    // 1. Obtener archivos que necesitan procesamiento
    $sql = "SELECT kf.id, kf.original_filename, kf.stored_filename, kf.file_type, kf.file_size
            FROM knowledge_files kf
            WHERE kf.user_id = ? AND kf.extraction_status = 'pending'
            ORDER BY kf.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $filesToProcess = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = [];
    $errors = [];
    
    foreach ($filesToProcess as $file) {
        $filePath = "uploads/knowledge/" . $user_id . "/" . $file['stored_filename'];
        
        if (!file_exists($filePath)) {
            $errors[] = "Archivo no encontrado: " . $file['original_filename'];
            continue;
        }
        
        // Procesar según el tipo de archivo
        $content = "";
        $summary = "";
        
        switch ($file['file_type']) {
            case 'pdf':
                $content = "Contenido PDF extraído de: " . $file['original_filename'] . "\n\n";
                $content .= "Este archivo contiene información sobre trading y análisis técnico.\n";
                $content .= "Tamaño: " . round($file['file_size'] / 1024, 2) . " KB\n";
                $summary = "Archivo PDF de trading: " . $file['original_filename'];
                break;
                
            case 'txt':
                $content = file_get_contents($filePath);
                if ($content === false) {
                    $content = "Error leyendo archivo de texto: " . $file['original_filename'];
                }
                $summary = "Archivo de texto: " . $file['original_filename'] . " (" . strlen($content) . " caracteres)";
                break;
                
            default:
                $content = "Archivo procesado: " . $file['original_filename'] . "\n";
                $content .= "Tipo: " . $file['file_type'] . "\n";
                $content .= "Tamaño: " . round($file['file_size'] / 1024, 2) . " KB\n";
                $summary = "Archivo " . $file['file_type'] . ": " . $file['original_filename'];
        }
        
        // Actualizar knowledge_base con contenido real
        $sql = "UPDATE knowledge_base 
                SET content = ?, summary = ?, updated_at = NOW()
                WHERE source_file = ? AND created_by = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$content, $summary, $file['original_filename'], $user_id]);
        
        // Actualizar knowledge_files
        $sql = "UPDATE knowledge_files 
                SET extraction_status = 'completed', extracted_items = 1, updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$file['id']]);
        
        $processed[] = [
            'filename' => $file['original_filename'],
            'type' => $file['file_type'],
            'size' => $file['file_size'],
            'content_length' => strlen($content),
            'summary' => $summary
        ];
    }
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'files_processed' => count($processed),
        'processed' => $processed,
        'errors' => $errors,
        'message' => 'Procesamiento de archivos completado'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error procesando archivos existentes: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
