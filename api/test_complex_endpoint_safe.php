<?php
// Test específico para endpoints complejos
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    // Simular request con JWT válido
    $config = require __DIR__ . '/config_simple_safe.php';
    
    // Crear JWT válido para usuario de prueba
    $payload = [
        'id' => 8,
        'email' => 'test@example.com',
        'exp' => time() + 3600
    ];
    
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payloadEncoded = base64_encode(json_encode($payload));
    $signature = hash_hmac('sha256', "$header.$payloadEncoded", $config['JWT_SECRET'], true);
    $signatureEncoded = base64_encode($signature);
    $token = "$header.$payloadEncoded.$signatureEncoded";
    
    $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
    
    // Test 1: Cargar config.php completo
    $configLoaded = false;
    $configError = null;
    $configOutput = '';
    
    try {
        ob_start();
        $fullConfig = require __DIR__ . '/config.php';
        $configOutput = ob_get_clean();
        $configLoaded = true;
    } catch (Throwable $e) {
        $configError = $e->getMessage();
        $configOutput = ob_get_clean();
    }
    
    // Test 2: Cargar helpers.php completo
    $helpersLoaded = false;
    $helpersError = null;
    $helpersOutput = '';
    
    try {
        ob_start();
        require_once __DIR__ . '/helpers.php';
        $helpersOutput = ob_get_clean();
        $helpersLoaded = true;
    } catch (Throwable $e) {
        $helpersError = $e->getMessage();
        $helpersOutput = ob_get_clean();
    }
    
    // Test 3: Cargar db.php completo
    $dbLoaded = false;
    $dbError = null;
    $dbOutput = '';
    
    try {
        ob_start();
        require_once __DIR__ . '/db.php';
        $dbOutput = ob_get_clean();
        $dbLoaded = true;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
        $dbOutput = ob_get_clean();
    }
    
    // Test 4: Cargar jwt.php completo
    $jwtLoaded = false;
    $jwtError = null;
    $jwtOutput = '';
    
    try {
        ob_start();
        require_once __DIR__ . '/jwt.php';
        $jwtOutput = ob_get_clean();
        $jwtLoaded = true;
    } catch (Throwable $e) {
        $jwtError = $e->getMessage();
        $jwtOutput = ob_get_clean();
    }
    
    // Test 5: Simular require_user()
    $requireUserTest = false;
    $requireUserError = null;
    
    try {
        if (function_exists('require_user')) {
            $user = require_user();
            $requireUserTest = true;
        } else {
            $requireUserError = 'require_user function not found';
        }
    } catch (Throwable $e) {
        $requireUserError = $e->getMessage();
    }
    
    // Test 6: Simular json_out()
    $jsonOutTest = false;
    $jsonOutError = null;
    
    try {
        if (function_exists('json_out')) {
            // No ejecutar json_out aquí para evitar output
            $jsonOutTest = true;
        } else {
            $jsonOutError = 'json_out function not found';
        }
    } catch (Throwable $e) {
        $jsonOutError = $e->getMessage();
    }
    
    echo json_encode([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Test de endpoint complejo completado',
        'jwt' => [
            'token' => $token,
            'payload' => $payload
        ],
        'file_loading_tests' => [
            'config_loaded' => $configLoaded,
            'config_error' => $configError,
            'config_output_length' => strlen($configOutput),
            'helpers_loaded' => $helpersLoaded,
            'helpers_error' => $helpersError,
            'helpers_output_length' => strlen($helpersOutput),
            'db_loaded' => $dbLoaded,
            'db_error' => $dbError,
            'db_output_length' => strlen($dbOutput),
            'jwt_loaded' => $jwtLoaded,
            'jwt_error' => $jwtError,
            'jwt_output_length' => strlen($jwtOutput)
        ],
        'function_tests' => [
            'require_user_test' => $requireUserTest,
            'require_user_error' => $requireUserError,
            'json_out_test' => $jsonOutTest,
            'json_out_error' => $jsonOutError
        ],
        'output_analysis' => [
            'config_has_output' => strlen($configOutput) > 0,
            'helpers_has_output' => strlen($helpersOutput) > 0,
            'db_has_output' => strlen($dbOutput) > 0,
            'jwt_has_output' => strlen($jwtOutput) > 0,
            'total_output_length' => strlen($configOutput) + strlen($helpersOutput) + strlen($dbOutput) + strlen($jwtOutput)
        ],
        'diagnosis' => [
            'all_files_load_successfully' => $configLoaded && $helpersLoaded && $dbLoaded && $jwtLoaded,
            'all_functions_work' => $requireUserTest && $jsonOutTest,
            'no_unexpected_output' => strlen($configOutput) + strlen($helpersOutput) + strlen($dbOutput) + strlen($jwtOutput) === 0,
            'ready_for_complex_endpoints' => $configLoaded && $helpersLoaded && $dbLoaded && $jwtLoaded && $requireUserTest && $jsonOutTest && (strlen($configOutput) + strlen($helpersOutput) + strlen($dbOutput) + strlen($jwtOutput) === 0)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => 'Error en test de endpoint complejo',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
