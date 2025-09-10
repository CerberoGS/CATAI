<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no válido', 400);
}

try {
    $pdo = db();
    
    $results = [
        'ok' => true,
        'user_id' => $user_id,
        'table_status' => [],
        'patterns_test' => ['count' => 0],
        'profile_test' => ['count' => 0],
        'metrics_test' => ['count' => 0],
        'debug_info' => [
            'php_version' => PHP_VERSION,
            'pdo_available' => extension_loaded('pdo'),
            'pdo_mysql_available' => extension_loaded('pdo_mysql'),
            'error_reporting' => error_reporting(),
            'display_errors' => ini_get('display_errors')
        ]
    ];

    // Verificar existencia de tablas
    $tables = ['ai_behavioral_patterns', 'ai_behavior_profiles', 'ai_learning_metrics'];
    
    foreach ($tables as $table) {
        try {
            // Verificar si la tabla existe
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $exists = $stmt->fetch() !== false;
            
            if (!$exists) {
                $results['table_status'][$table] = "ERROR: Table does not exist";
                continue;
            }
            
            // Verificar estructura de la tabla
            $stmt = $pdo->prepare("DESCRIBE `$table`");
            $stmt->execute();
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results['table_status'][$table] = "OK: Table exists with " . count($columns) . " columns";
            
            // Probar consulta básica
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM `$table` WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($table === 'ai_behavioral_patterns') {
                $results['patterns_test']['count'] = (int)$count['count'];
            } elseif ($table === 'ai_behavior_profiles') {
                $results['profile_test']['count'] = (int)$count['count'];
            } elseif ($table === 'ai_learning_metrics') {
                $results['metrics_test']['count'] = (int)$count['count'];
            }
            
        } catch (Exception $e) {
            $results['table_status'][$table] = "ERROR: " . $e->getMessage();
        }
    }

    json_out($results);

} catch (Exception $e) {
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}