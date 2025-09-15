<?php
// Crear usuario de prueba para testing
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
    
    // Verificar si ya existe usuario de prueba
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ?");
    $stmt->execute(['test@example.com']);
    $existingUser = $stmt->fetch();
    
    if ($existingUser) {
        echo json_encode([
            'ok' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Usuario de prueba ya existe',
            'user' => $existingUser,
            'action' => 'existing'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Crear usuario de prueba
        $testEmail = 'test@example.com';
        $testPassword = 'testpassword123';
        $testName = 'Usuario de Prueba';
        $passwordHash = password_hash($testPassword, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
        $stmt->execute([$testEmail, $passwordHash, $testName]);
        
        $userId = $pdo->lastInsertId();
        
        echo json_encode([
            'ok' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Usuario de prueba creado exitosamente',
            'user' => [
                'id' => $userId,
                'email' => $testEmail,
                'name' => $testName
            ],
            'credentials' => [
                'email' => $testEmail,
                'password' => $testPassword
            ],
            'action' => 'created'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error creando usuario de prueba',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
