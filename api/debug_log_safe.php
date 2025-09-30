<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// Función para escribir logs a archivo
function write_debug_log($message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if (!empty($context)) {
        $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $logMessage .= "\n";
    
    $logFile = __DIR__ . '/logs/debug_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Función para leer logs
function read_debug_logs($lines = 50) {
    $logFile = __DIR__ . '/logs/debug_' . date('Y-m-d') . '.log';
    if (!file_exists($logFile)) {
        return ['logs' => [], 'message' => 'No hay logs para hoy'];
    }
    
    $logs = file($logFile, FILE_IGNORE_NEW_LINES);
    $logs = array_slice($logs, -$lines);
    
    return ['logs' => $logs];
}

json_header();

try {
    $u = require_user();
    
    $action = $_GET['action'] ?? 'read';
    
    if ($action === 'write') {
        $input = json_input(true);
        $message = $input['message'] ?? 'No message';
        $context = $input['context'] ?? [];
        
        write_debug_log($message, $context);
        json_out(['ok' => true, 'message' => 'Log escrito']);
    } else {
        $lines = (int)($_GET['lines'] ?? 50);
        $result = read_debug_logs($lines);
        json_out($result);
    }
    
} catch (Throwable $e) {
    error_log("Error en debug_log_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
