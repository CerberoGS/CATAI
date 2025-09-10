<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    // Validación de método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return json_error('Método no permitido', 405);
    }

    // Logging detallado para debugging
    error_log("=== DEBUG UPLOAD ===");
    error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
    error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'No definido'));
    error_log("USER ID from token: " . $user_id);
    error_log("FILES array: " . print_r($_FILES, true));
    error_log("POST array: " . print_r($_POST, true));
    error_log("Upload directory will be: " . __DIR__ . "/uploads/knowledge/{$user_id}/");

    // Validación de archivos
    if (empty($_FILES)) {
        error_log("ERROR: \$_FILES está completamente vacío");
        error_log("Content-Type recibido: " . ($_SERVER['CONTENT_TYPE'] ?? 'No definido'));
        error_log("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'No definido'));
        return json_error('No se recibieron archivos en la petición', 400);
    }
    
    if (!isset($_FILES['files']) || empty($_FILES['files']['name'])) {
        error_log("ERROR: No se encontraron archivos en \$_FILES['files']");
        error_log("Claves disponibles en \$_FILES: " . implode(', ', array_keys($_FILES)));
        return json_error('No se encontraron archivos para subir', 400);
    }

    // Configuración de subida
    $maxFileSize = 10 * 1024 * 1024; // 10MB
    $allowedTypes = ['pdf', 'txt', 'doc', 'docx'];
$allowedMimes = [
    'application/pdf',
    'text/plain',
    'text/csv',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

    $uploadDir = __DIR__ . '/uploads/knowledge/' . $user_id;
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return json_error('No se pudo crear directorio de subida', 500);
        }
    }

    $uploadedFiles = [];
    $files = $_FILES['files'];
    
    // Logging de estructura de archivos
    error_log("Files structure: " . print_r($files, true));
    
    // Determinar si es un archivo único o múltiples archivos
    $isMultiple = is_array($files['name']);
    $fileCount = $isMultiple ? count($files['name']) : 1;
    
    error_log("Is multiple files: " . ($isMultiple ? 'YES' : 'NO'));
    error_log("File count: " . $fileCount);

    // Procesar archivos
    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = $isMultiple ? $files['name'][$i] : $files['name'];
        $tmpName = $isMultiple ? $files['tmp_name'][$i] : $files['tmp_name'];
        $fileSize = $isMultiple ? $files['size'][$i] : $files['size'];
        $fileError = $isMultiple ? $files['error'][$i] : $files['error'];
        
        error_log("Processing file $i: $fileName, size: $fileSize, error: $fileError");
        
        // Verificar que el archivo tiene nombre
        if (empty($fileName)) {
            error_log("Skipping file $i: empty filename");
            continue;
        }

        // Validar error de subida
        if ($fileError !== UPLOAD_ERR_OK) {
            error_log("Upload error for file $fileName: $fileError");
            continue;
        }

        // Validar tamaño
        if ($fileSize > $maxFileSize) {
            error_log("File too large: $fileName ($fileSize bytes)");
            continue;
        }

        // Validar tipo de archivo
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        error_log("File extension: $fileExtension for file $fileName");
        error_log("Allowed extensions: " . implode(', ', $allowedTypes));
        if (!in_array($fileExtension, $allowedTypes)) {
            error_log("Invalid file extension: $fileExtension for file $fileName");
            continue;
        }

        // Validar MIME type
        $mimeType = mime_content_type($tmpName);
        error_log("MIME type: $mimeType for file $fileName");
        error_log("Allowed MIME types: " . implode(', ', $allowedMimes));
        if (!in_array($mimeType, $allowedMimes)) {
            error_log("Invalid MIME type: $mimeType for file $fileName");
            continue;
        }
        
        error_log("File validation passed for: $fileName");

        // Generar nombre único
        $uniqueName = $user_id . '_' . time() . '_' . uniqid() . '.' . $fileExtension;
        $destination = $uploadDir . '/' . $uniqueName;

        // Mover archivo
        if (!move_uploaded_file($tmpName, $destination)) {
            error_log("Failed to move file $fileName to $destination");
            continue;
        }
        
        error_log("File moved successfully to: $destination");

        // Obtener hash del archivo
        $fileHash = hash_file('sha256', $destination);

        // Guardar en base de datos
        $pdo = db();
        error_log("Database connection established");
        
        // Insertar en knowledge_files
        $stmt = $pdo->prepare("
            INSERT INTO knowledge_files 
            (user_id, original_filename, stored_filename, file_type, file_size, mime_type, upload_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'uploaded', NOW())
        ");
        
        $stmt->execute([
            $user_id,
            $fileName,
            $uniqueName,
            $fileExtension,
            $fileSize,
            $mimeType
        ]);
        
        $fileId = $pdo->lastInsertId();
        error_log("File inserted in knowledge_files with ID: $fileId");

        // Crear entrada en knowledge_base
        $stmt = $pdo->prepare("
            INSERT INTO knowledge_base 
            (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_type, source_file, is_public, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'ai_extraction', ?, 0, 1, NOW())
        ");
        
        $title = 'Conocimiento extraído del archivo';
        $content = 'Contenido extraído automáticamente del archivo subido.';
        $summary = 'Resumen del conocimiento extraído';
        $tags = json_encode(['extraído', 'archivo']);
        
        $stmt->execute([
            'user_insight',
            $title,
            $content,
            $summary,
            $tags,
            0.70,
            $user_id,
            $fileName
        ]);
        
        $knowledgeId = $pdo->lastInsertId();
        error_log("Knowledge entry created with ID: $knowledgeId");

        $uploadedFiles[] = [
            'id' => $fileId,
            'original_filename' => $fileName,
            'stored_filename' => $uniqueName,
            'file_type' => $fileExtension,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'hash' => $fileHash
        ];
        
        error_log("File $fileName successfully processed and added to uploadedFiles array");
    }

    if (empty($uploadedFiles)) {
        error_log("ERROR: No files were successfully uploaded");
        return json_error('No se pudieron subir archivos válidos', 400);
    }

    error_log("SUCCESS: " . count($uploadedFiles) . " files uploaded successfully");
    return json_out([
        'ok' => true,
        'uploaded_count' => count($uploadedFiles),
        'files' => $uploadedFiles
    ]);

} catch (Exception $e) {
    error_log("Error en ai_upload_knowledge_safe.php: " . $e->getMessage());
    return json_error('Error interno del servidor', 500);
}


