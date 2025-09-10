<?php
// Habilitar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verificar si los archivos existen
$helpers_exists = file_exists('helpers.php');
$db_exists = file_exists('db.php');

$result = [
    'ok' => true,
    'files_exist' => [
        'helpers.php' => $helpers_exists,
        'db.php' => $db_exists
    ],
    'php_version' => PHP_VERSION,
    'error_reporting' => error_reporting(),
    'display_errors' => ini_get('display_errors')
];

// Intentar cargar helpers.php
if ($helpers_exists) {
    try {
        require_once 'helpers.php';
        $result['helpers_loaded'] = true;
        
        // Verificar si las funciones existen
        $result['functions_exist'] = [
            'require_user' => function_exists('require_user'),
            'json_out' => function_exists('json_out'),
            'json_error' => function_exists('json_error'),
            'apply_cors' => function_exists('apply_cors')
        ];
    } catch (Exception $e) {
        $result['helpers_error'] = $e->getMessage();
    }
} else {
    $result['helpers_error'] = 'helpers.php no existe';
}

// Intentar cargar db.php
if ($db_exists) {
    try {
        require_once 'db.php';
        $result['db_loaded'] = true;
        
        // Verificar si la función db existe
        $result['db_function_exists'] = function_exists('db');
    } catch (Exception $e) {
        $result['db_error'] = $e->getMessage();
    }
} else {
    $result['db_error'] = 'db.php no existe';
}

// Intentar autenticación
if (isset($result['helpers_loaded']) && $result['helpers_loaded']) {
    try {
        $user = require_user();
        $result['auth_success'] = true;
        $result['user_id'] = $user['user_id'] ?? $user['id'] ?? null;
    } catch (Exception $e) {
        $result['auth_error'] = $e->getMessage();
    }
}

// Intentar conexión a base de datos
if (isset($result['db_loaded']) && $result['db_loaded']) {
    try {
        $pdo = db();
        $result['db_connection'] = true;
        
        // Verificar tablas
        $tables = ['ai_behavioral_patterns', 'ai_behavior_profiles', 'ai_learning_metrics'];
        $table_check = [];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                $exists = $stmt->fetch() !== false;
                $table_check[$table] = $exists;
            } catch (Exception $e) {
                $table_check[$table] = 'ERROR: ' . $e->getMessage();
            }
        }
        
        $result['tables'] = $table_check;
        
    } catch (Exception $e) {
        $result['db_connection_error'] = $e->getMessage();
    }
}

// Devolver resultado
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
?>
