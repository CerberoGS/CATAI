<?php
declare(strict_types=1);

// Versión de diagnóstico del endpoint de vista previa
header('Content-Type: application/json; charset=utf-8');
header_remove('X-Powered-By');

try {
    // Capturar cualquier salida accidental
    if (!ob_get_level()) { ob_start(); }
    
    $debug = [
        'step' => 'init',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION
    ];
    
    // Paso 1: Cargar archivos
    $debug['step'] = 'loading_files';
    $debug['files'] = [];
    
    $files = ['config.php', 'helpers.php', 'db.php', 'jwt.php'];
    foreach ($files as $file) {
        $path = __DIR__ . '/' . $file;
        $debug['files'][$file] = [
            'exists' => file_exists($path),
            'readable' => is_readable($path),
            'size' => file_exists($path) ? filesize($path) : 0
        ];
        
        if (file_exists($path)) {
            try {
                require_once $path;
                $debug['files'][$file]['loaded'] = true;
            } catch (Throwable $e) {
                $debug['files'][$file]['error'] = $e->getMessage();
            }
        }
    }
    
    // Paso 2: Verificar funciones
    $debug['step'] = 'checking_functions';
    $debug['functions'] = [
        'apply_cors' => function_exists('apply_cors'),
        'require_user' => function_exists('require_user'),
        'read_json_body' => function_exists('read_json_body'),
        'json_out' => function_exists('json_out'),
        'json_error' => function_exists('json_error'),
        'db' => function_exists('db')
    ];
    
    // Paso 3: CORS
    $debug['step'] = 'cors';
    try {
        if (function_exists('apply_cors')) {
            apply_cors();
            $debug['cors'] = 'applied';
        } else {
            $debug['cors'] = 'function_not_found';
        }
    } catch (Throwable $e) {
        $debug['cors'] = 'error: ' . $e->getMessage();
    }
    
    // Paso 4: OPTIONS check
    $debug['step'] = 'options_check';
    $debug['method'] = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $debug['options'] = 'exiting';
        exit;
    }
    
    // Paso 5: Auth
    $debug['step'] = 'auth';
    try {
        if (function_exists('require_user')) {
            $user = require_user();
            $debug['auth'] = 'success';
            $debug['user_id'] = $user['id'] ?? 'unknown';
        } else {
            $debug['auth'] = 'function_not_found';
        }
    } catch (Throwable $e) {
        $debug['auth'] = 'error: ' . $e->getMessage();
        $debug['auth_error_line'] = $e->getLine();
        $debug['auth_error_file'] = $e->getFile();
    }
    
    // Verificar si hay fugas de salida
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    if ($leak !== '') {
        $debug['output_leak'] = $leak;
    }
    
    echo json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
