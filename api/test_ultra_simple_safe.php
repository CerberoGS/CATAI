<?php
// Test ultra simple - solo verificar que PHP funciona
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'ok' => true,
    'php_version' => PHP_VERSION,
    'timestamp' => date('Y-m-d H:i:s'),
    'message' => 'PHP funciona correctamente'
], JSON_UNESCAPED_UNICODE);
