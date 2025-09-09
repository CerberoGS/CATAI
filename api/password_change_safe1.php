<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Requerir autenticación
try {
    $user = require_user();
} catch (Exception $e) {
    json_error('Error de autenticación: ' . $e->getMessage(), 401);
}

// Obtener datos del POST
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Datos JSON inválidos');
}

$current_password = $input['current_password'] ?? '';
$new_password = $input['new_password'] ?? '';

// Validaciones
if (empty($current_password)) {
    json_error('Contraseña actual requerida');
}

if (empty($new_password)) {
    json_error('Nueva contraseña requerida');
}

if (strlen($new_password) < 8) {
    json_error('La nueva contraseña debe tener al menos 8 caracteres');
}

// Verificar contraseña actual
try {
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        json_error('Usuario no encontrado');
    }

    if (!password_verify($current_password, $user_data['password'])) {
        json_error('Contraseña actual incorrecta');
    }
} catch (Exception $e) {
    json_error('Error de base de datos: ' . $e->getMessage());
}

// Hash de la nueva contraseña
$new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

// Actualizar contraseña
try {
    $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$new_password_hash, $user['id']]);

    if (!$result) {
        json_error('Error al actualizar contraseña en la base de datos');
    }
} catch (Exception $e) {
    json_error('Error de base de datos: ' . $e->getMessage());
}

json_out([
    'ok' => true,
    'message' => 'Contraseña actualizada correctamente'
]);
?>