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

// Respuesta sin consultas SQL
$results = [
    'ok' => true,
    'user_id' => $user_id,
    'timestamp' => date('Y-m-d H:i:s'),
    'message' => 'Prueba sin consultas SQL completada',
    'test' => 'success',
    'diagnosis' => 'El problema está en las consultas SQL, no en el servidor PHP'
];

json_out($results);
?>
