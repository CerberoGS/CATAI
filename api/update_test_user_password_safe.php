<?php
// Actualizar contraseña del usuario de prueba existente
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // Cargar configuración
    $config = require __DIR__ . '/config_simple_safe.php';
    
    // Conectar a base de datos
    $host = $config['DB_HOST'];
    $port = $config['DB_PORT'];
    $name = $config['DB_NAME'];
    $user = $config['DB_USER'];
    $pass = $config['DB_PASS'];
    
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    // Verificar que existe usuario de prueba
    $stmt = $pdo->prepare("SELECT id, email, password_hash, name FROM users WHERE email = ?");
    $stmt->execute(['test@example.com']);
    $existingUser = $stmt->fetch();
    
    if (!$existingUser) {
        echo json_encode([
            'ok' => false,
            'message' => 'Usuario de prueba no existe. Ejecuta create_test_user_safe.php primero.',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Actualizar contraseña del usuario existente
    $newPassword = 'testpassword123';
    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE email = ?");
    $stmt->execute([$newPasswordHash, 'test@example.com']);
    
    // Verificar que se actualizó correctamente
    $stmt = $pdo->prepare("SELECT id, email, password_hash, name FROM users WHERE email = ?");
    $stmt->execute(['test@example.com']);
    $updatedUser = $stmt->fetch();
    
    // Verificar que la nueva contraseña funciona
    $passwordValid = password_verify($newPassword, $updatedUser['password_hash']);
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Contraseña del usuario de prueba actualizada exitosamente',
        'user' => [
            'id' => (int)$updatedUser['id'],
            'email' => $updatedUser['email'],
            'name' => $updatedUser['name']
        ],
        'credentials' => [
            'email' => 'test@example.com',
            'password' => $newPassword
        ],
        'password_test' => [
            'hash_updated' => true,
            'password_valid' => $passwordValid,
            'old_hash' => $existingUser['password_hash'],
            'new_hash' => $updatedUser['password_hash']
        ],
        'action' => 'password_updated'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error actualizando contraseña del usuario de prueba',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
