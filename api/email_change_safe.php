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

$new_email = $input['new_email'] ?? '';

// Validaciones
if (empty($new_email)) {
    json_error('Nuevo email requerido');
}

if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
    json_error('Email inválido');
}

// Normalizar email
$new_email = normalize_email($new_email);

try {
    // Verificar que el email no esté en uso
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$new_email, $user['id']]);
    if ($stmt->fetch()) {
        json_error('Este email ya está en uso');
    }

    // Actualizar email
    $stmt = $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$new_email, $user['id']]);

    if (!$result) {
        json_error('Error al actualizar email en la base de datos');
    }
} catch (Exception $e) {
    json_error('Error de base de datos: ' . $e->getMessage());
}

json_out([
    'ok' => true,
    'message' => 'Email actualizado correctamente',
    'new_email' => $new_email
]);
?>