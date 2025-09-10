<?php
// Habilitar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Probar si helpers.php se carga sin errores
try {
    require_once 'helpers.php';
    $result = [
        'ok' => true,
        'message' => 'helpers.php cargado correctamente',
        'functions_available' => [
            'apply_cors' => function_exists('apply_cors'),
            'require_user' => function_exists('require_user'),
            'json_out' => function_exists('json_out'),
            'json_error' => function_exists('json_error')
        ]
    ];
} catch (Exception $e) {
    $result = [
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
} catch (Error $e) {
    $result = [
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'type' => 'PHP Error'
    ];
}

// Aplicar CORS si está disponible
if (function_exists('apply_cors')) {
    apply_cors();
}

// Devolver resultado
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
?>
