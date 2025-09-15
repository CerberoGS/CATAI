<?php
// Test de dependencias paso a paso
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$results = [
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'tests' => []
];

// Test 1: Incluir config.php
try {
    require_once __DIR__ . '/config.php';
    $results['tests']['config'] = ['status' => 'OK', 'message' => 'config.php cargado correctamente'];
} catch (Exception $e) {
    $results['tests']['config'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

// Test 2: Incluir helpers.php
try {
    require_once __DIR__ . '/helpers.php';
    $results['tests']['helpers'] = ['status' => 'OK', 'message' => 'helpers.php cargado correctamente'];
} catch (Exception $e) {
    $results['tests']['helpers'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

// Test 3: Incluir db.php
try {
    require_once __DIR__ . '/db.php';
    $results['tests']['db'] = ['status' => 'OK', 'message' => 'db.php cargado correctamente'];
} catch (Exception $e) {
    $results['tests']['db'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

// Test 4: Aplicar CORS
try {
    apply_cors();
    $results['tests']['cors'] = ['status' => 'OK', 'message' => 'CORS aplicado correctamente'];
} catch (Exception $e) {
    $results['tests']['cors'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

// Test 5: Conexión a base de datos
try {
    $stmt = $pdo->query('SELECT 1 as test');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && $result['test'] == 1) {
        $results['tests']['database'] = ['status' => 'OK', 'message' => 'Conexión a base de datos exitosa'];
    } else {
        $results['tests']['database'] = ['status' => 'ERROR', 'message' => 'Consulta de prueba falló'];
        $results['ok'] = false;
    }
} catch (Exception $e) {
    $results['tests']['database'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

// Test 6: Verificar tablas necesarias
try {
    $tables = ['user_settings', 'knowledge_files', 'knowledge_base'];
    $existingTables = [];
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
        }
    }
    
    $results['tests']['tables'] = [
        'status' => 'OK', 
        'message' => 'Tablas verificadas',
        'existing' => $existingTables,
        'missing' => array_diff($tables, $existingTables)
    ];
} catch (Exception $e) {
    $results['tests']['tables'] = ['status' => 'ERROR', 'message' => $e->getMessage()];
    $results['ok'] = false;
}

echo json_encode($results);
?>
