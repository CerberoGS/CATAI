<?php
declare(strict_types=1);

// Debug endpoint para probar
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'helpers.php';
require_once 'db.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Debug: mostrar headers
$headers = getallheaders();
error_log("Headers: " . print_r($headers, true));

// Debug: mostrar input
$raw_input = file_get_contents('php://input');
error_log("Raw input: " . $raw_input);

// Requerir autenticación
try {
    $user = require_user();
    error_log("User data: " . print_r($user, true));
} catch (Exception $e) {
    error_log("Auth error: " . $e->getMessage());
    json_error('Error de autenticación: ' . $e->getMessage(), 401);
}

// Obtener datos del POST
$input = json_decode($raw_input, true);
if (!$input) {
    error_log("JSON decode failed");
    json_error('Datos JSON inválidos');
}

$new_name = $input['new_name'] ?? '';

// Validaciones
if (empty($new_name)) {
    json_error('Nuevo nombre requerido');
}

if (strlen($new_name) < 2) {
    json_error('El nombre debe tener al menos 2 caracteres');
}

if (strlen($new_name) > 100) {
    json_error('El nombre no puede exceder 100 caracteres');
}

// Sanitizar nombre
$new_name = trim($new_name);

// Actualizar nombre
try {
    $stmt = $pdo->prepare("UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$new_name, $user['id']]);

    if (!$result) {
        json_error('Error al actualizar nombre en la base de datos');
    }
} catch (Exception $e) {
    error_log("DB error: " . $e->getMessage());
    json_error('Error de base de datos: ' . $e->getMessage());
}

json_out([
    'ok' => true,
    'message' => 'Nombre actualizado correctamente',
    'new_name' => $new_name
]);
?>
