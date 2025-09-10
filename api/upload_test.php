<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    $user_id = $user['id'];
    
    $uploadDir = __DIR__ . '/uploads/knowledge/' . $user_id;
    $baseDir = __DIR__ . '/uploads/knowledge';
    
    // Verificar si el directorio base existe
    $baseExists = is_dir($baseDir);
    
    // Verificar si el directorio del usuario existe
    $userDirExists = is_dir($uploadDir);
    
    // Verificar permisos de escritura
    $writable = false;
    if ($userDirExists) {
        $writable = is_writable($uploadDir);
    } else if ($baseExists) {
        $writable = is_writable($baseDir);
    }
    
    // Intentar crear el directorio si no existe
    if (!$userDirExists && $baseExists) {
        $created = @mkdir($uploadDir, 0755, true);
        if ($created) {
            $userDirExists = true;
            $writable = is_writable($uploadDir);
        }
    }
    
    return json_out([
        'ok' => true,
        'base_dir' => $baseDir,
        'user_dir' => $uploadDir,
        'base_exists' => $baseExists,
        'user_dir_exists' => $userDirExists,
        'writable' => $writable,
        'permissions' => $userDirExists ? substr(sprintf('%o', fileperms($uploadDir)), -4) : 'N/A',
        'can_create' => $baseExists ? is_writable($baseDir) : false
    ]);

} catch (Exception $e) {
    error_log("Error en upload_test.php: " . $e->getMessage());
    return json_error('Error interno del servidor', 500);
}
