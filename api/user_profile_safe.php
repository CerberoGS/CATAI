<?php
declare(strict_types=1);

// Endpoint súper limpio - solo JSON
ob_clean(); // Limpiar cualquier output previo
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/api/helpers.php';
    require_once __DIR__ . '/api/db.php';
    
    // Solo GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido');
    }
    
    // Requerir autenticación
    $user = require_user();
    
    // Leer datos reales de la base de datos
    $stmt = $pdo->prepare("SELECT id, email, name, is_admin FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Convertir is_admin a role
    $userData['role'] = $userData['is_admin'] ? 'admin' : 'user';
    unset($userData['is_admin']);
    
    // Solo un echo al final
    echo json_encode($userData);
    exit;
    
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>