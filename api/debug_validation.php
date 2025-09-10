<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Logging ultra detallado
error_log("=== DEBUG VALIDATION ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'No definido'));
error_log("CONTENT_LENGTH: " . ($_SERVER['CONTENT_LENGTH'] ?? 'No definido'));

// Obtener ID del usuario
$user_id = $user['user_id'] ?? $user['id'] ?? null;
error_log("USER ID from token: " . $user_id);

// Configuración de validación (igual que el endpoint principal)
$maxFileSize = 10 * 1024 * 1024; // 10MB
$allowedTypes = ['pdf', 'txt', 'doc', 'docx'];
$allowedMimes = [
    'application/pdf',
    'text/plain',
    'text/csv',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

// Debug de $_FILES
error_log("=== \$_FILES DEBUG ===");
if (empty($_FILES)) {
    error_log("ERROR: \$_FILES está completamente vacío");
    json_out([
        'ok' => false,
        'error' => 'No se recibieron archivos',
        'debug' => [
            'files_count' => 0,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'No definido',
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'No definido'
        ]
    ]);
    exit;
}

$validation_results = [];

foreach ($_FILES as $key => $file) {
    error_log("Procesando archivo '$key': " . print_r($file, true));
    
    $result = [
        'field_name' => $key,
        'original_name' => $file['name'] ?? 'No definido',
        'size' => $file['size'] ?? 0,
        'error' => $file['error'] ?? 'No definido',
        'tmp_name' => $file['tmp_name'] ?? 'No definido',
        'type' => $file['type'] ?? 'No definido',
        'validations' => []
    ];
    
    // Validación 1: Error de subida
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['validations']['upload_error'] = [
            'passed' => false,
            'error_code' => $file['error'],
            'message' => 'Error en la subida del archivo'
        ];
    } else {
        $result['validations']['upload_error'] = [
            'passed' => true,
            'message' => 'Archivo subido correctamente'
        ];
    }
    
    // Validación 2: Tamaño
    if ($file['size'] > $maxFileSize) {
        $result['validations']['file_size'] = [
            'passed' => false,
            'file_size' => $file['size'],
            'max_allowed' => $maxFileSize,
            'message' => 'Archivo demasiado grande'
        ];
    } else {
        $result['validations']['file_size'] = [
            'passed' => true,
            'file_size' => $file['size'],
            'max_allowed' => $maxFileSize,
            'message' => 'Tamaño válido'
        ];
    }
    
    // Validación 3: Extensión
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedTypes)) {
        $result['validations']['file_extension'] = [
            'passed' => false,
            'file_extension' => $fileExtension,
            'allowed_extensions' => $allowedTypes,
            'message' => 'Extensión no permitida'
        ];
    } else {
        $result['validations']['file_extension'] = [
            'passed' => true,
            'file_extension' => $fileExtension,
            'allowed_extensions' => $allowedTypes,
            'message' => 'Extensión válida'
        ];
    }
    
    // Validación 4: MIME type
    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimes)) {
        $result['validations']['mime_type'] = [
            'passed' => false,
            'detected_mime' => $mimeType,
            'allowed_mimes' => $allowedMimes,
            'message' => 'Tipo MIME no permitido'
        ];
    } else {
        $result['validations']['mime_type'] = [
            'passed' => true,
            'detected_mime' => $mimeType,
            'allowed_mimes' => $allowedMimes,
            'message' => 'Tipo MIME válido'
        ];
    }
    
    // Resumen de validaciones
    $all_passed = true;
    foreach ($result['validations'] as $validation) {
        if (!$validation['passed']) {
            $all_passed = false;
            break;
        }
    }
    
    $result['all_validations_passed'] = $all_passed;
    $validation_results[] = $result;
}

// Respuesta de debug
json_out([
    'ok' => true,
    'debug_validation' => [
        'user_id' => $user_id,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'No definido',
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'No definido',
        'files_count' => count($_FILES),
        'validation_config' => [
            'max_file_size' => $maxFileSize,
            'allowed_types' => $allowedTypes,
            'allowed_mimes' => $allowedMimes
        ],
        'files' => $validation_results
    ]
]);
