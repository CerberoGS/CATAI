<?php
declare(strict_types=1);

// Habilitar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Logging detallado
$logFile = __DIR__ . '/logs/debug_view_button.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function debugLog($message, $data = null) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if ($data !== null) {
        $logMessage .= " | Data: " . json_encode($data);
    }
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

try {
    debugLog("=== DEBUG VIEW BUTTON START ===");
    
    // Verificar autenticación
    debugLog("Checking authentication...");
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    debugLog("User authenticated", ['user_id' => $user_id]);
    
    if (!$user_id) {
        debugLog("ERROR: Invalid user");
        json_error('Usuario no válido', 400);
    }

    // Obtener parámetros
    $knowledge_id = (int)($_GET['id'] ?? 0);
    debugLog("Knowledge ID requested", ['knowledge_id' => $knowledge_id]);
    
    if (!$knowledge_id) {
        debugLog("ERROR: No knowledge ID provided");
        json_error('ID de conocimiento requerido', 400);
    }

    $pdo = db();
    debugLog("Database connection established");

    // Paso 1: Verificar si existe el conocimiento
    debugLog("Step 1: Checking if knowledge exists...");
    $stmt = $pdo->prepare("
        SELECT 
            id,
            knowledge_type,
            title,
            content,
            summary,
            tags,
            confidence_score,
            usage_count,
            success_rate,
            symbol,
            sector,
            source_type,
            source_file,
            is_public,
            is_active,
            created_at,
            updated_at
        FROM knowledge_base 
        WHERE id = ? AND created_by = ?
    ");
    
    $stmt->execute([$knowledge_id, $user_id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    debugLog("Knowledge query result", [
        'found' => $knowledge !== false,
        'knowledge_id' => $knowledge_id,
        'user_id' => $user_id
    ]);

    if (!$knowledge) {
        debugLog("ERROR: Knowledge not found");
        json_error('Conocimiento no encontrado', 404);
    }

    debugLog("Knowledge found", [
        'id' => $knowledge['id'],
        'title' => $knowledge['title'],
        'source_file' => $knowledge['source_file']
    ]);

    // Paso 2: Obtener archivo asociado
    debugLog("Step 2: Getting associated file...");
    $stmt = $pdo->prepare("
        SELECT 
            kf.original_filename,
            kf.stored_filename,
            kf.file_type,
            kf.file_size,
            kf.mime_type,
            kf.upload_status,
            kf.extraction_status,
            kf.extracted_items
        FROM knowledge_files kf
        WHERE kf.original_filename = ? AND kf.user_id = ?
    ");
    
    $stmt->execute([$knowledge['source_file'], $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    debugLog("File query result", [
        'found' => $file !== false,
        'source_file' => $knowledge['source_file'],
        'user_id' => $user_id
    ]);

    if ($file) {
        debugLog("File found", [
            'original_filename' => $file['original_filename'],
            'file_type' => $file['file_type'],
            'file_size' => $file['file_size']
        ]);
        
        // Agregar datos del archivo al conocimiento
        $knowledge['original_filename'] = $file['original_filename'];
        $knowledge['stored_filename'] = $file['stored_filename'];
        $knowledge['file_type'] = $file['file_type'];
        $knowledge['file_size'] = $file['file_size'];
        $knowledge['mime_type'] = $file['mime_type'];
        $knowledge['upload_status'] = $file['upload_status'];
        $knowledge['extraction_status'] = $file['extraction_status'];
        $knowledge['extracted_items'] = $file['extracted_items'];
    } else {
        debugLog("WARNING: File not found for knowledge", [
            'source_file' => $knowledge['source_file'],
            'user_id' => $user_id
        ]);
    }

    // Paso 3: Preparar respuesta
    debugLog("Step 3: Preparing response...");
    $response = [
        'ok' => true,
        'knowledge' => $knowledge,
        'debug' => [
            'user_id' => $user_id,
            'knowledge_id' => $knowledge_id,
            'knowledge_found' => true,
            'file_found' => $file !== false,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    debugLog("Response prepared successfully", [
        'response_ok' => $response['ok'],
        'knowledge_id' => $response['knowledge']['id'],
        'file_found' => $response['debug']['file_found']
    ]);

    debugLog("=== DEBUG VIEW BUTTON END ===");
    
    json_out($response);

} catch (Exception $e) {
    debugLog("EXCEPTION: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
} catch (Error $e) {
    debugLog("PHP ERROR: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
