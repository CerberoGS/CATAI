<?php
// Test de carga de config.php y helpers.php
header('Content-Type: application/json; charset=utf-8');

$results = [
    'ok' => false,
    'tests' => [],
    'errors' => []
];

try {
    // Test 1: Cargar config.php
    $results['tests']['config_php'] = 'Cargando...';
    require_once __DIR__ . '/config.php';
    $results['tests']['config_php'] = '✅ config.php cargado correctamente';
    
    // Test 2: Verificar variables de configuración
    if (isset($CONFIG)) {
        $results['tests']['config_vars'] = '✅ Variables CONFIG disponibles';
        $results['config_vars'] = array_keys($CONFIG);
    } else {
        $results['tests']['config_vars'] = '❌ Variables CONFIG no disponibles';
        $results['errors'][] = 'CONFIG no definido';
    }
    
    // Test 3: Cargar helpers.php
    $results['tests']['helpers_php'] = 'Cargando...';
    require_once __DIR__ . '/helpers.php';
    $results['tests']['helpers_php'] = '✅ helpers.php cargado correctamente';
    
    // Test 3.5: Cargar db.php
    $results['tests']['db_php'] = 'Cargando...';
    require_once __DIR__ . '/db.php';
    $results['tests']['db_php'] = '✅ db.php cargado correctamente';
    
    // Test 4: Verificar funciones helper
    $helperFunctions = [
        'json_out' => 'json_out',
        'json_error' => 'json_error', 
        'ok' => 'ok',
        'db' => 'db',
        'require_user' => 'require_user'
    ];
    
    foreach ($helperFunctions as $expected => $actual) {
        if (function_exists($actual)) {
            $results['tests']["function_$expected"] = "✅ $actual disponible";
        } else {
            $results['tests']["function_$expected"] = "❌ $actual no disponible";
            $results['errors'][] = "Función $actual no encontrada";
        }
    }
    
    // Test 5: Verificar conexión DB
    try {
        $pdo = db();
        $results['tests']['db_connection'] = '✅ Conexión DB exitosa';
    } catch (Exception $e) {
        $results['tests']['db_connection'] = '❌ Error de conexión DB';
        $results['errors'][] = 'DB Error: ' . $e->getMessage();
    }
    
    $results['ok'] = empty($results['errors']);
    
} catch (Exception $e) {
    $results['errors'][] = 'Error general: ' . $e->getMessage();
    $results['ok'] = false;
} catch (Error $e) {
    $results['errors'][] = 'Error fatal: ' . $e->getMessage();
    $results['ok'] = false;
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>
