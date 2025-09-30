<?php
// /catai/api/debug_logs_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  // Obtener la configuración de logs de PHP
  $logFile = ini_get('error_log');
  $logDir = dirname($logFile);
  
  // Posibles ubicaciones de logs
  $possibleLogs = [
    $logFile,
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log',
    '/var/log/nginx/error.log',
    '/home/u522228883/logs/error.log',
    '/home/u522228883/domains/cerberogrowthsolutions.com/logs/error.log',
    __DIR__ . '/logs/error.log'
  ];
  
  $logsFound = [];
  $recentLogs = [];
  
  foreach ($possibleLogs as $logPath) {
    if (file_exists($logPath) && is_readable($logPath)) {
      $logsFound[] = $logPath;
      
      // Leer últimas 20 líneas que contengan nuestros logs
      $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      $filteredLines = array_filter($lines, function($line) {
        return strpos($line, 'test_data_provider_safe.php') !== false || 
               strpos($line, 'DEBUG') !== false ||
               strpos($line, 'ERROR') !== false;
      });
      
      $recentLogs[$logPath] = array_slice($filteredLines, -20);
    }
  }
  
  // También crear un log de prueba
  error_log("DEBUG debug_logs_safe.php - Log de prueba creado en: " . date('Y-m-d H:i:s'));
  
  json_out([
    'ok' => true,
    'php_error_log' => $logFile,
    'logs_found' => $logsFound,
    'recent_logs' => $recentLogs,
    'test_log_created' => true,
    'current_time' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
  ]);
  
} catch (Exception $e) {
  error_log("Error en debug_logs_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
