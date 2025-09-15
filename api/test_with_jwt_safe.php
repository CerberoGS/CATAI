<?php
// Test con JWT válido para verificar endpoints complejos
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
    
    // Obtener usuario de prueba
    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE email = ?");
    $stmt->execute(['test@example.com']);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode([
            'ok' => false,
            'message' => 'Usuario de prueba no existe',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Crear JWT válido
    $payload = [
        'id' => (int)$user['id'],
        'email' => $user['email'],
        'exp' => time() + 3600 // 1 hora
    ];
    
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payloadEncoded = base64_encode(json_encode($payload));
    $signature = hash_hmac('sha256', "$header.$payloadEncoded", $config['JWT_SECRET'], true);
    $signatureEncoded = base64_encode($signature);
    $token = "$header.$payloadEncoded.$signatureEncoded";
    
    // Simular request con JWT
    $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
    
    // Intentar cargar helpers.php
    $helpersLoaded = false;
    $helpersError = null;
    
    try {
        require_once __DIR__ . '/helpers.php';
        $helpersLoaded = true;
    } catch (Throwable $e) {
        $helpersError = $e->getMessage();
    }
    
    // Intentar cargar db.php
    $dbLoaded = false;
    $dbError = null;
    
    try {
        require_once __DIR__ . '/db.php';
        $dbLoaded = true;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
    
    // Intentar cargar jwt.php
    $jwtLoaded = false;
    $jwtError = null;
    
    try {
        require_once __DIR__ . '/jwt.php';
        $jwtLoaded = true;
    } catch (Throwable $e) {
        $jwtError = $e->getMessage();
    }
    
    // Verificar funciones críticas
    $functionsExist = [
        'json_out' => function_exists('json_out'),
        'read_json_body' => function_exists('read_json_body'),
        'jwt_sign' => function_exists('jwt_sign'),
        'jwt_verify_hs256' => function_exists('jwt_verify_hs256'),
        'require_user' => function_exists('require_user'),
        'db' => function_exists('db')
    ];
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test con JWT completado',
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['name']
        ],
        'jwt' => [
            'token' => $token,
            'payload' => $payload
        ],
        'file_loading' => [
            'helpers_loaded' => $helpersLoaded,
            'helpers_error' => $helpersError,
            'db_loaded' => $dbLoaded,
            'db_error' => $dbError,
            'jwt_loaded' => $jwtLoaded,
            'jwt_error' => $jwtError
        ],
        'functions_exist' => $functionsExist,
        'diagnosis' => [
            'all_files_loaded' => $helpersLoaded && $dbLoaded && $jwtLoaded,
            'all_functions_exist' => array_reduce($functionsExist, function($carry, $item) { return $carry && $item; }, true),
            'ready_for_complex_endpoints' => $helpersLoaded && $dbLoaded && $jwtLoaded && array_reduce($functionsExist, function($carry, $item) { return $carry && $item; }, true)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en test con JWT',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
