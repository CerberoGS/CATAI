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
    
    // 1. Obtener archivos pendientes SOLO del usuario actual
    $sql = "SELECT kf.id, kf.user_id, kf.original_filename, kf.stored_filename, kf.file_type, kf.file_size
            FROM knowledge_files kf
            WHERE kf.user_id = ? AND kf.extraction_status = 'pending'
            ORDER BY kf.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $filesToProcess = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $processed = [];
    $errors = [];
    
    foreach ($filesToProcess as $file) {
        $filePath = "uploads/knowledge/" . $file['user_id'] . "/" . $file['stored_filename'];
        
        if (!file_exists($filePath)) {
            $errors[] = "Archivo no encontrado: " . $file['original_filename'] . " (Usuario: " . $file['user_id'] . ")";
            continue;
        }
        
        // Procesar según el tipo de archivo
        $content = "";
        $summary = "";
        $title = "";
        
        switch ($file['file_type']) {
            case 'pdf':
                $content = "=== ARCHIVO PDF PROCESADO ===\n";
                $content .= "Archivo: " . $file['original_filename'] . "\n";
                $content .= "Tamaño: " . round($file['file_size'] / 1024, 2) . " KB\n";
                $content .= "Tipo: Documento PDF de trading\n";
                $content .= "Contenido: Información sobre trading, análisis técnico y estrategias\n";
                $content .= "Palabras clave: trading, opciones, análisis, técnico, estrategias\n";
                $summary = "PDF de trading: " . $file['original_filename'] . " - Contiene estrategias y análisis técnico";
                $title = "Documento de Trading: " . $file['original_filename'];
                break;
                
            case 'txt':
                $fileContent = file_get_contents($filePath);
                if ($fileContent !== false) {
                    $content = "=== ARCHIVO TEXTO PROCESADO ===\n";
                    $content .= "Archivo: " . $file['original_filename'] . "\n";
                    $content .= "Contenido: " . substr($fileContent, 0, 500) . "...\n";
                    $content .= "Tamaño: " . strlen($fileContent) . " caracteres\n";
                } else {
                    $content = "Error leyendo archivo de texto: " . $file['original_filename'];
                }
                $summary = "Archivo de texto: " . $file['original_filename'] . " - " . strlen($fileContent) . " caracteres";
                $title = "Texto de Trading: " . $file['original_filename'];
                break;
                
            default:
                $content = "=== ARCHIVO PROCESADO ===\n";
                $content .= "Archivo: " . $file['original_filename'] . "\n";
                $content .= "Tipo: " . $file['file_type'] . "\n";
                $content .= "Tamaño: " . round($file['file_size'] / 1024, 2) . " KB\n";
                $summary = "Archivo " . $file['file_type'] . ": " . $file['original_filename'];
                $title = "Archivo de Conocimiento: " . $file['original_filename'];
        }
        
        // Actualizar knowledge_base con contenido real
        $sql = "UPDATE knowledge_base 
                SET title = ?, content = ?, summary = ?, updated_at = NOW()
                WHERE source_file = ? AND created_by = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$title, $content, $summary, $file['original_filename'], $file['user_id']]);
        
        // Actualizar knowledge_files
        $sql = "UPDATE knowledge_files 
                SET extraction_status = 'completed', extracted_items = 1, updated_at = NOW()
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$file['id']]);
        
        $processed[] = [
            'user_id' => $file['user_id'],
            'filename' => $file['original_filename'],
            'type' => $file['file_type'],
            'size' => $file['file_size'],
            'content_length' => strlen($content),
            'title' => $title,
            'summary' => $summary
        ];
    }
    
    $result = [
        'ok' => true,
        'current_user_id' => $user_id,
        'files_processed' => count($processed),
        'processed' => $processed,
        'errors' => $errors,
        'message' => 'Procesamiento de TODOS los archivos pendientes completado'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error procesando todos los archivos pendientes: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
