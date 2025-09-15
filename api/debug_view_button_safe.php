<?php
declare(strict_types=1);

// Debug endpoint para diagnosticar el botón "Ver Detalles"
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Función para escribir logs de debug
function writeDebugLog($message, $data = null) {
    $logFile = __DIR__ . '/logs/debug_view_button.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message";
    
    if ($data !== null) {
        $logEntry .= " | Data: " . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    
    $logEntry .= "\n";
    
    // Crear directorio de logs si no existe
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true)) {
            // Si no se puede crear el directorio, usar un archivo temporal
            $logFile = sys_get_temp_dir() . '/debug_view_button.log';
        }
    }
    
    // Verificar permisos de escritura
    if (is_writable($logDir) || is_writable(dirname($logFile))) {
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } else {
        // Fallback: escribir en error_log
        error_log("DEBUG VIEW BUTTON: $logEntry");
    }
}

try {
    writeDebugLog("=== INICIO DEBUG VIEW BUTTON ===");
    
    // Verificar método HTTP
    writeDebugLog("Método HTTP", $_SERVER['REQUEST_METHOD']);
    
    // Verificar parámetros
    writeDebugLog("Parámetros GET", $_GET);
    writeDebugLog("Parámetros POST", $_POST);
    
    // Verificar headers
    $headers = getallheaders();
    writeDebugLog("Headers recibidos", $headers);
    
    // Verificar token de autorización
    $authHeader = $headers['Authorization'] ?? '';
    writeDebugLog("Header Authorization", $authHeader);
    
    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        writeDebugLog("ERROR: Token de autorización faltante o inválido");
        json_error('Token de autorización requerido', 401);
        exit;
    }
    
    $token = substr($authHeader, 7);
    writeDebugLog("Token extraído", substr($token, 0, 20) . '...');
    
    // Verificar JWT
    try {
        $user = jwt_verify($token);
        writeDebugLog("Usuario verificado", ['id' => $user['id'], 'email' => $user['email']]);
    } catch (Exception $e) {
        writeDebugLog("ERROR: JWT inválido", $e->getMessage());
        json_error('Token inválido', 401);
        exit;
    }
    
    // Verificar parámetro ID
    $knowledgeId = $_GET['id'] ?? null;
    writeDebugLog("Knowledge ID recibido", $knowledgeId);
    
    if (empty($knowledgeId)) {
        writeDebugLog("ERROR: Knowledge ID faltante");
        json_error('ID de conocimiento requerido', 400);
        exit;
    }
    
    // Verificar conexión a base de datos
    try {
        $pdo = db();
        writeDebugLog("Conexión a DB exitosa");
    } catch (Exception $e) {
        writeDebugLog("ERROR: Conexión a DB fallida", $e->getMessage());
        json_error('Error de conexión a base de datos', 500);
        exit;
    }
    
    // Buscar el conocimiento en la base de datos
    try {
        $stmt = $pdo->prepare("
            SELECT kb.*, kf.original_filename, kf.file_type, kf.file_size, kf.mime_type
            FROM knowledge_base kb
            LEFT JOIN knowledge_files kf ON kb.source_file = kf.original_filename
            WHERE kb.id = ? AND kb.created_by = ?
        ");
        
        $stmt->execute([$knowledgeId, $user['id']]);
        $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
        
        writeDebugLog("Consulta ejecutada", ['knowledge_id' => $knowledgeId, 'user_id' => $user['id']]);
        
        if (!$knowledge) {
            writeDebugLog("ERROR: Conocimiento no encontrado");
            json_error('Conocimiento no encontrado', 404);
        exit;
        }
        
        writeDebugLog("Conocimiento encontrado", [
            'id' => $knowledge['id'],
            'title' => $knowledge['title'],
            'source_file' => $knowledge['source_file'],
            'original_filename' => $knowledge['original_filename']
        ]);
        
        // Preparar respuesta
        $response = [
            'ok' => true,
            'knowledge' => $knowledge,
            'debug_info' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $user['id'],
                'knowledge_id' => $knowledgeId,
                'file_found' => !empty($knowledge['original_filename']),
                'file_type' => $knowledge['file_type'] ?? 'unknown'
            ]
        ];
        
        writeDebugLog("Respuesta preparada", $response['debug_info']);
        
        json_out($response);
        
    } catch (Exception $e) {
        writeDebugLog("ERROR: Error en consulta SQL", $e->getMessage());
        json_error('Error en consulta de base de datos', 500);
        exit;
    }
    
} catch (Exception $e) {
    writeDebugLog("ERROR CRÍTICO", $e->getMessage());
    json_error('Error interno del servidor', 500);
}
