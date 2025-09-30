<?php
declare(strict_types=1);

// Función para logging limpio en archivo específico
function clean_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    
    // Crear directorio logs si no existe
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Escribir en archivo específico
    $logFile = $logDir . '/ai_extract_debug_' . date('Y-m-d') . '.log';
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

try {
    clean_log("=== DIAGNÓSTICO ESPECÍFICO PASO 4 ===");
    
    // Incluir archivos necesarios
    require_once 'config.php';
    require_once 'db.php';
    require_once 'helpers.php';
    require_once 'Crypto_safe.php';
    
    // Conectar a BD
    $pdo = db();
    clean_log("Conexión a BD OK");
    
    // PASO 4A: Probar require_user() paso a paso
    clean_log("PASO 4A: Iniciando require_user()...");
    
    try {
        // Verificar headers
        $headers = getallheaders();
        clean_log("Headers recibidos: " . json_encode($headers));
        
        // Verificar Authorization header
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        clean_log("Authorization header: " . $authHeader);
        
        if (empty($authHeader)) {
            clean_log("ERROR: No hay header Authorization");
            json_error('Token de autorización requerido');
        }
        
        // Extraer token
        if (strpos($authHeader, 'Bearer ') !== 0) {
            clean_log("ERROR: Formato de token inválido");
            json_error('Formato de token inválido');
        }
        
        $token = substr($authHeader, 7);
        clean_log("Token extraído: " . substr($token, 0, 20) . "...");
        
        // Verificar JWT
        $secret = $GLOBALS['CONFIG']['JWT_SECRET'] ?? 'fallback-secret';
        [$ok, $jwt] = jwt_verify_hs256($token, $secret);
        if (!$ok) {
            clean_log("ERROR: Token JWT inválido: " . (is_string($jwt) ? $jwt : 'invalid-token'));
            json_error('Token inválido');
        }
        
        clean_log("JWT verificado OK");
        clean_log("JWT payload: " . json_encode($jwt));
        
        // Obtener usuario
        $userId = $jwt['user_id'] ?? $jwt['id'] ?? null;
        if (!$userId) {
            clean_log("ERROR: Token sin user_id o id");
            clean_log("Campos disponibles en JWT: " . implode(', ', array_keys($jwt)));
            json_error('Token sin user_id');
        }
        
        clean_log("User ID del JWT: $userId");
        
        // Consultar usuario en BD
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            clean_log("ERROR: Usuario no encontrado en BD: ID=$userId");
            json_error('Usuario no encontrado');
        }
        
        clean_log("Usuario encontrado en BD: " . $user['email']);
        
        clean_log("PASO 4A: require_user() completado exitosamente");
        
    } catch (Exception $e) {
        clean_log("ERROR en require_user(): " . $e->getMessage());
        clean_log("Stack trace: " . $e->getTraceAsString());
        json_error("Error en autenticación: " . $e->getMessage());
    }
    
    // PASO 4B: Probar json_input() paso a paso
    clean_log("PASO 4B: Iniciando json_input()...");
    
    try {
        // Verificar método HTTP
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        clean_log("Método HTTP: $method");
        
        if ($method !== 'POST') {
            clean_log("ERROR: Método no es POST");
            json_error('Método no permitido');
        }
        
        // Verificar Content-Type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        clean_log("Content-Type: $contentType");
        
        // Leer input
        $inputRaw = file_get_contents('php://input');
        clean_log("Input raw (primeros 100 chars): " . substr($inputRaw, 0, 100));
        
        if (empty($inputRaw)) {
            clean_log("ERROR: Input vacío");
            json_error('Input vacío');
        }
        
        // Decodificar JSON
        $input = json_decode($inputRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            clean_log("ERROR: JSON inválido: " . json_last_error_msg());
            json_error('JSON inválido');
        }
        
        clean_log("JSON decodificado OK: " . json_encode($input));
        
        clean_log("PASO 4B: json_input() completado exitosamente");
        
    } catch (Exception $e) {
        clean_log("ERROR en json_input(): " . $e->getMessage());
        clean_log("Stack trace: " . $e->getTraceAsString());
        json_error("Error en input: " . $e->getMessage());
    }
    
    // PASO 4C: Probar ambas funciones juntas (como en el código original)
    clean_log("PASO 4C: Probando require_user() y json_input() juntos...");
    
    try {
        $user = require_user();
        $userId = $user['id'];
        clean_log("Usuario obtenido: ID=$userId, email=" . $user['email']);
        
        $input = json_input();
        $fileId = (int)($input['file_id'] ?? 0);
        clean_log("Input obtenido: file_id=$fileId");
        
        clean_log("PASO 4C: Ambas funciones funcionan juntas OK");
        
    } catch (Exception $e) {
        clean_log("ERROR en funciones combinadas: " . $e->getMessage());
        clean_log("Stack trace: " . $e->getTraceAsString());
        json_error("Error en funciones combinadas: " . $e->getMessage());
    }
    
    clean_log("=== DIAGNÓSTICO PASO 4 COMPLETADO EXITOSAMENTE ===");
    
    json_out([
        'ok' => true,
        'message' => 'Diagnóstico PASO 4 completado exitosamente',
        'user_id' => $userId,
        'input' => $input
    ]);
    
} catch (Exception $e) {
    clean_log("ERROR FATAL en diagnóstico PASO 4: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());
    json_error("Error fatal en diagnóstico PASO 4: " . $e->getMessage());
} catch (Error $e) {
    clean_log("FATAL ERROR en diagnóstico PASO 4: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());
    json_error("Error fatal en diagnóstico PASO 4: " . $e->getMessage());
}
?>
