<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Endpoint completo para subida de archivos a IA
 * Maneja: subida → file_id → vector store → base de datos
 */
try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('method_not_allowed', 405, 'Only POST method allowed');
    }

    // Logging detallado
    error_log("=== AI FILE UPLOAD COMPLETE ===");
    error_log("User ID: $user_id");
    error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'No definido'));

    // Validar archivos
    if (empty($_FILES) || !isset($_FILES['file'])) {
        json_error('no_file', 400, 'No file provided');
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('upload_error', 400, 'Upload error: ' . $file['error']);
    }

    // Configuración
    $maxFileSize = 10 * 1024 * 1024; // 10MB
    $allowedTypes = ['pdf', 'txt', 'doc', 'docx', 'csv'];
    $allowedMimes = [
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    // Validaciones
    if ($file['size'] > $maxFileSize) {
        json_error('file_too_large', 400, 'File size exceeds 10MB limit');
    }

    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedTypes)) {
        json_error('invalid_file_type', 400, 'File type not allowed: ' . $fileExtension);
    }

    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimes)) {
        json_error('invalid_mime_type', 400, 'MIME type not allowed: ' . $mimeType);
    }

    // Crear directorio de subida
    $uploadDir = __DIR__ . '/uploads/knowledge/' . $user_id;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            json_error('directory_error', 500, 'Could not create upload directory');
        }
    }

    // Generar nombre único y mover archivo
    $uniqueName = $user_id . '_' . time() . '_' . uniqid() . '.' . $fileExtension;
    $destination = $uploadDir . '/' . $uniqueName;
    
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        json_error('move_failed', 500, 'Failed to move uploaded file');
    }

    // Obtener hash del archivo
    $fileHash = hash_file('sha256', $destination);

    // Conectar a base de datos
    $pdo = db();

    // Crear tabla knowledge_files si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS knowledge_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        file_type VARCHAR(10) NOT NULL,
        file_size INT NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_hash VARCHAR(64) NOT NULL,
        upload_status ENUM('uploaded', 'processing', 'processed', 'error') DEFAULT 'uploaded',
        ai_file_id VARCHAR(255) NULL,
        vector_store_id VARCHAR(255) NULL,
        extraction_status ENUM('pending', 'extracting', 'extracted', 'failed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_status (upload_status),
        INDEX idx_extraction (extraction_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insertar registro del archivo
    $stmt = $pdo->prepare("
        INSERT INTO knowledge_files 
        (user_id, original_filename, stored_filename, file_type, file_size, mime_type, file_hash, upload_status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'uploaded')
    ");
    
    $stmt->execute([
        $user_id,
        $file['name'],
        $uniqueName,
        $fileExtension,
        $file['size'],
        $mimeType,
        $fileHash
    ]);
    
    $fileId = $pdo->lastInsertId();
    error_log("File registered in database with ID: $fileId");

    // Preparar respuesta inicial
    $response = [
        'ok' => true,
        'file_id' => $fileId,
        'original_filename' => $file['name'],
        'stored_filename' => $uniqueName,
        'file_size' => $file['size'],
        'file_type' => $fileExtension,
        'upload_status' => 'uploaded',
        'next_steps' => [
            'upload_to_ai' => true,
            'create_vector_store' => true,
            'extract_content' => true
        ]
    ];

    // TODO: Aquí implementaremos la integración con APIs de IA
    // Por ahora, marcamos como procesado para testing
    $stmt = $pdo->prepare("
        UPDATE knowledge_files 
        SET upload_status = 'processing', 
            extraction_status = 'pending',
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$fileId]);

    error_log("File upload completed successfully: " . json_encode($response));
    json_out($response);

} catch (Exception $e) {
    error_log("Error in ai_file_upload_complete_safe.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    json_error('internal_error', 500, 'Internal server error: ' . $e->getMessage());
}
