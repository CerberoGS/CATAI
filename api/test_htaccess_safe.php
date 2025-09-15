<?php
// Test específico para verificar que .htaccess no interfiere
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Verificar variables de servidor
$serverInfo = [
    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'NOT_SET',
    'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'NOT_SET',
    'PATH_INFO' => $_SERVER['PATH_INFO'] ?? 'NOT_SET',
    'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? 'NOT_SET',
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'NOT_SET',
    'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'NOT_SET',
    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'NOT_SET',
    'HTTPS' => $_SERVER['HTTPS'] ?? 'NOT_SET',
    'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? 'NOT_SET'
];

// Verificar si hay problemas de rewrite
$rewriteIssues = [];
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === false) {
    $rewriteIssues[] = 'REQUEST_URI no contiene /api/';
}
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') === false) {
    $rewriteIssues[] = 'SCRIPT_NAME no contiene /api/';
}

echo json_encode([
    'ok' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'message' => 'Test de .htaccess completado',
    'server_info' => $serverInfo,
    'rewrite_issues' => $rewriteIssues,
    'htaccess_status' => empty($rewriteIssues) ? 'OK' : 'ISSUES_DETECTED'
], JSON_UNESCAPED_UNICODE);
