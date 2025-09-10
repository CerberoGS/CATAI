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

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        json_error('Datos JSON inválidos', 400);
    }

    $logs = $input['logs'] ?? [];
    $source = $input['source'] ?? 'unknown';

    // Crear directorio de logs si no existe
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Guardar logs en archivo
    $logFile = $logDir . '/debug_' . $source . '_' . date('Y-m-d_H-i-s') . '.log';
    $logContent = "=== DEBUG LOGS FROM $source ===\n";
    $logContent .= "User ID: $user_id\n";
    $logContent .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $logContent .= "Logs Count: " . count($logs) . "\n\n";

    foreach ($logs as $log) {
        $logContent .= "[{$log['timestamp']}] {$log['level']}: " . implode(' ', $log['data']) . "\n";
    }

    file_put_contents($logFile, $logContent, LOCK_EX);

    json_out([
        'ok' => true,
        'message' => 'Logs guardados correctamente',
        'log_file' => $logFile,
        'logs_count' => count($logs)
    ]);

} catch (Exception $e) {
    error_log("Error en log_debug.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}