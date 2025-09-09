<?php
declare(strict_types=1);

// Endpoint de prueba sin autenticación
header('Content-Type: application/json');

try {
    require_once 'api/db.php';
    
    // Leer datos del usuario tester@t.t (ID 4)
    $stmt = $pdo->prepare("SELECT id, email, name, is_admin FROM users WHERE email = ?");
    $stmt->execute(['tester@t.t']);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        echo json_encode(['error' => 'Usuario tester@t.t no encontrado']);
        exit;
    }
    
    // Convertir is_admin a role
    $userData['role'] = $userData['is_admin'] ? 'admin' : 'user';
    unset($userData['is_admin']);
    
    echo json_encode($userData);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Error DB: ' . $e->getMessage()]);
}
?>
