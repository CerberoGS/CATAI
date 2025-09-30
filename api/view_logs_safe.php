<?php
/**
 * Endpoint temporal para ver logs del servidor
 * SOLO PARA DEBUG - ELIMINAR EN PRODUCCIÓN
 */

require_once 'helpers.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('Usuario no autenticado', 401);
    }
    
    // Rutas posibles de logs
    $logPaths = [
        '/home/u522228883/domains/cerberogrowthsolutions.com/public_html/catai/error_log_cerberogrowthsolutions_com',
        '/home/u522228883/domains/cerberogrowthsolutions.com/public_html/catai/api/error_log',
        '/home/u522228883/logs/php_errors.log',
        __DIR__ . '/error_log',
        __DIR__ . '/../error_log'
    ];
    
    $logs = [];
    
    foreach ($logPaths as $path) {
        if (file_exists($path)) {
            $content = file_get_contents($path);
            $logs[basename($path)] = [
                'path' => $path,
                'size' => filesize($path),
                'modified' => date('Y-m-d H:i:s', filemtime($path)),
                'lines' => substr_count($content, "\n"),
                'last_50_lines' => array_slice(explode("\n", $content), -50)
            ];
        }
    }
    
    // También mostrar los últimos logs de error_log() de PHP
    $recentLogs = [];
    
    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'log_files' => $logs ?: [],
        'message' => count($logs) > 0 ? 'Logs encontrados' : 'No se encontraron archivos de log'
    ]);
    
} catch (Exception $e) {
    error_log("Error en view_logs_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage(), 500);
}
?>
