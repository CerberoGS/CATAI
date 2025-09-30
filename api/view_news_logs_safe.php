<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

json_header();

try {
    $u = require_user();
    
    // Obtener los últimos logs de error
    $logFile = __DIR__ . '/../debug_unknown_' . date('Y-m-d') . '_*.log';
    $logFiles = glob($logFile);
    
    $logs = [];
    foreach ($logFiles as $file) {
        $content = file_get_contents($file);
        if ($content) {
            $lines = explode("\n", $content);
            // Obtener las últimas 20 líneas
            $logs = array_merge($logs, array_slice($lines, -20));
        }
    }
    
    // Filtrar solo logs relacionados con news
    $newsLogs = array_filter($logs, function($line) {
        return strpos($line, 'news') !== false || strpos($line, 'set_user_news_key') !== false;
    });
    
    json_out([
        'ok' => true,
        'logs' => array_values($newsLogs),
        'total_lines' => count($logs),
        'news_lines' => count($newsLogs)
    ]);
    
} catch (Throwable $e) {
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
