<?php
declare(strict_types=1);

// Debug endpoint para ver el error exacto
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

try {
    echo json_encode(['debug' => 'Starting debug...']);
    
    require_once __DIR__ . '/api/helpers.php';
    echo json_encode(['debug' => 'helpers.php loaded']);
    
    require_once __DIR__ . '/api/db.php';
    echo json_encode(['debug' => 'db.php loaded']);
    
    // Solo GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(['error' => 'Método no permitido']);
        exit;
    }
    
    echo json_encode(['debug' => 'Method OK']);
    
    // Requerir autenticación
    $user = require_user();
    echo json_encode(['debug' => 'User authenticated', 'user_id' => $user['id']]);
    
    // Leer datos reales de la base de datos usando el ID del JWT
    $stmt = $pdo->prepare("SELECT id, email, name, is_admin FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }
    
    echo json_encode(['debug' => 'User data fetched', 'data' => $userData]);
    
    // Convertir is_admin a role
    $userData['role'] = $userData['is_admin'] ? 'admin' : 'user';
    unset($userData['is_admin']);
    
    echo json_encode($userData);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Error: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
?>
