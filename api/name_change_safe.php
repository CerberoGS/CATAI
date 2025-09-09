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

try {
    // Primero verificar que el usuario existe
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existingUser) {
        json_error('Usuario no encontrado');
    }
    
    // Actualizar nombre
    $stmt = $pdo->prepare("UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$new_name, $user['id']]);

    if (!$result) {
        json_error('Error al actualizar nombre en la base de datos');
    }
    
    // Verificar que se actualizó correctamente
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($updatedUser['name'] !== $new_name) {
        json_error('Error: el nombre no se actualizó correctamente');
    }

} catch (Exception $e) {
    json_error('Error de base de datos: ' . $e->getMessage());
}

json_out([
    'ok' => true,
    'message' => 'Nombre actualizado correctamente',
    'new_name' => $new_name
]);
?>