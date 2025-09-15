<?php
// Test paso a paso para identificar exactamente dónde falla
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$steps = [];
$currentStep = 0;

try {
    // Paso 1: Verificar que PHP básico funciona
    $currentStep = 1;
    $steps['step_1_php'] = [
        'description' => 'PHP básico',
        'status' => 'SUCCESS',
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // Paso 2: Verificar que config.php se puede cargar
    $currentStep = 2;
    try {
        $config = require __DIR__ . '/config.php';
        $steps['step_2_config'] = [
            'description' => 'Cargar config.php',
            'status' => 'SUCCESS',
            'config_keys' => array_keys($config),
            'base_url' => $config['BASE_URL'] ?? 'NOT_SET'
        ];
    } catch (Throwable $e) {
        $steps['step_2_config'] = [
            'description' => 'Cargar config.php',
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // Paso 3: Verificar que helpers.php se puede cargar
    $currentStep = 3;
    try {
        require_once __DIR__ . '/helpers.php';
        $steps['step_3_helpers'] = [
            'description' => 'Cargar helpers.php',
            'status' => 'SUCCESS',
            'functions_available' => [
                'json_out' => function_exists('json_out'),
                'read_json_body' => function_exists('read_json_body'),
                'normalize_email' => function_exists('normalize_email'),
                'cfg' => function_exists('cfg')
            ]
        ];
    } catch (Throwable $e) {
        $steps['step_3_helpers'] = [
            'description' => 'Cargar helpers.php',
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // Paso 4: Verificar que db.php se puede cargar
    $currentStep = 4;
    try {
        require_once __DIR__ . '/db.php';
        $steps['step_4_db'] = [
            'description' => 'Cargar db.php',
            'status' => 'SUCCESS',
            'db_function_exists' => function_exists('db')
        ];
    } catch (Throwable $e) {
        $steps['step_4_db'] = [
            'description' => 'Cargar db.php',
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // Paso 5: Verificar que jwt.php se puede cargar
    $currentStep = 5;
    try {
        require_once __DIR__ . '/jwt.php';
        $steps['step_5_jwt'] = [
            'description' => 'Cargar jwt.php',
            'status' => 'SUCCESS',
            'jwt_functions' => [
                'jwt_sign' => function_exists('jwt_sign'),
                'jwt_decode_user' => function_exists('jwt_decode_user')
            ]
        ];
    } catch (Throwable $e) {
        $steps['step_5_jwt'] = [
            'description' => 'Cargar jwt.php',
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // Paso 6: Verificar conexión a base de datos
    $currentStep = 6;
    try {
        if (function_exists('db')) {
            $pdo = db();
            $steps['step_6_database'] = [
                'description' => 'Conexión a base de datos',
                'status' => 'SUCCESS',
                'pdo_class' => get_class($pdo),
                'connection_test' => 'OK'
            ];
        } else {
            $steps['step_6_database'] = [
                'description' => 'Conexión a base de datos',
                'status' => 'FAILED',
                'error' => 'db() function not available'
            ];
        }
    } catch (Throwable $e) {
        $steps['step_6_database'] = [
            'description' => 'Conexión a base de datos',
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // Paso 7: Verificar función json_out
    $currentStep = 7;
    try {
        if (function_exists('json_out')) {
            // No llamamos json_out aquí para evitar output duplicado
            $steps['step_7_json_out'] = [
                'description' => 'Función json_out',
                'status' => 'SUCCESS',
                'function_exists' => true
            ];
        } else {
            $steps['step_7_json_out'] = [
                'description' => 'Función json_out',
                'status' => 'FAILED',
                'error' => 'json_out function not available'
            ];
        }
    } catch (Throwable $e) {
        $steps['step_7_json_out'] = [
            'description' => 'Función json_out',
            'status' => 'FAILED',
            'error' => $e->getMessage()
        ];
    }

    // Respuesta exitosa
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test paso a paso completado exitosamente',
        'steps_completed' => $currentStep,
        'steps' => $steps
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Respuesta de error
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test paso a paso falló',
        'failed_at_step' => $currentStep,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'steps_completed' => $steps
    ], JSON_UNESCAPED_UNICODE);
}
