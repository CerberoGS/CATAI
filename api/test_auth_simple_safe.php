<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

json_header();

try {
    $user = require_user();
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$userId) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    json_out([
        'ok' => true,
        'user' => $user,
        'user_id' => $userId,
        'message' => 'Autenticación exitosa'
    ]);
    
} catch (Throwable $e) {
    error_log("test_auth_simple_safe.php error: " . $e->getMessage());
    json_error('Error interno del servidor', 500);
}