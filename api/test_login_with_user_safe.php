<?php
// Test de login con usuario de prueba
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
    $stmt = $pdo->prepare("SELECT id, email, password_hash, name, is_active FROM users WHERE email = ?");
    $stmt->execute(['test@example.com']);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode([
            'ok' => false,
            'message' => 'Usuario de prueba no existe. Ejecuta create_test_user_safe.php primero.',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Simular login con usuario de prueba
    $testPassword = 'testpassword123';
    
    if (password_verify($testPassword, $user['password_hash'])) {
        // Login exitoso - simular JWT
        $payload = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'exp' => time() + 3600 // 1 hora
        ];
        
        // JWT simple (sin librería externa)
        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payloadEncoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payloadEncoded", $config['JWT_SECRET'], true);
        $signatureEncoded = base64_encode($signature);
        $token = "$header.$payloadEncoded.$signatureEncoded";
        
        echo json_encode([
            'ok' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => 'Login de prueba exitoso',
            'login_test' => [
                'user_found' => true,
                'password_valid' => true,
                'user_active' => (int)$user['is_active'] === 1,
                'jwt_created' => true
            ],
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'name' => $user['name']
            ],
            'token' => $token
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'ok' => false,
            'message' => 'Contraseña incorrecta para usuario de prueba',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en test de login',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
