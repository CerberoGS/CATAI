<?php
declare(strict_types=1);

require_once 'helpers.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no autenticado', 401);
}

json_out([
    'ok' => true,
    'message' => 'Endpoint de prueba funcionando',
    'user_id' => $user_id,
    'timestamp' => date('Y-m-d H:i:s'),
    'test' => 'success'
]);
?>
