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
error_log("=== DEBUG UPLOAD ULTRA DETALLADO ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'No definido'));
error_log("CONTENT_LENGTH: " . ($_SERVER['CONTENT_LENGTH'] ?? 'No definido'));
error_log("REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'No definido'));
error_log("HTTP_USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'No definido'));

// Obtener ID del usuario
$user_id = $user['user_id'] ?? $user['id'] ?? null;
error_log("USER ID from token: " . $user_id);

// Debug de $_FILES
error_log("=== \$_FILES DEBUG ===");
if (empty($_FILES)) {
    error_log("ERROR: \$_FILES está completamente vacío");
} else {
    error_log("Claves en \$_FILES: " . implode(', ', array_keys($_FILES)));
    foreach ($_FILES as $key => $file) {
        error_log("Archivo '$key': " . print_r($file, true));
    }
}

// Debug de $_POST
error_log("=== \$_POST DEBUG ===");
if (empty($_POST)) {
    error_log("INFO: \$_POST está vacío (normal para multipart/form-data)");
} else {
    error_log("Claves en \$_POST: " . implode(', ', array_keys($_POST)));
    foreach ($_POST as $key => $value) {
        error_log("POST '$key': " . print_r($value, true));
    }
}

// Debug de input raw
error_log("=== RAW INPUT DEBUG ===");
$raw_input = file_get_contents('php://input');
error_log("Raw input length: " . strlen($raw_input));
error_log("Raw input preview (first 200 chars): " . substr($raw_input, 0, 200));

// Debug de headers
error_log("=== HEADERS DEBUG ===");
foreach (getallheaders() as $name => $value) {
    error_log("Header $name: $value");
}

// Verificar configuración PHP
error_log("=== PHP CONFIG DEBUG ===");
error_log("upload_max_filesize: " . ini_get('upload_max_filesize'));
error_log("post_max_size: " . ini_get('post_max_size'));
error_log("max_file_uploads: " . ini_get('max_file_uploads'));
error_log("file_uploads: " . (ini_get('file_uploads') ? 'ON' : 'OFF'));

// Verificar directorio de subida
$upload_dir = __DIR__ . "/uploads/knowledge/{$user_id}/";
error_log("Upload directory: " . $upload_dir);
error_log("Directory exists: " . (is_dir($upload_dir) ? 'YES' : 'NO'));
error_log("Directory writable: " . (is_writable($upload_dir) ? 'YES' : 'NO'));

// Respuesta de debug
json_out([
    'ok' => true,
    'debug' => [
        'user_id' => $user_id,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'No definido',
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'No definido',
        'files_count' => count($_FILES),
        'files_keys' => array_keys($_FILES),
        'post_count' => count($_POST),
        'post_keys' => array_keys($_POST),
        'upload_dir' => $upload_dir,
        'upload_dir_exists' => is_dir($upload_dir),
        'upload_dir_writable' => is_writable($upload_dir),
        'php_config' => [
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'file_uploads' => ini_get('file_uploads')
        ]
    ]
]);
