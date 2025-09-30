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
    clean_log("=== DIAGNÓSTICO SIMPLE ===");
    
    // PASO 1: Incluir archivos básicos
    clean_log("PASO 1: Incluyendo archivos...");
    require_once 'config.php';
    clean_log("config.php incluido OK");
    
    require_once 'db.php';
    clean_log("db.php incluido OK");
    
    require_once 'helpers.php';
    clean_log("helpers.php incluido OK");
    
    require_once 'Crypto_safe.php';
    clean_log("Crypto_safe.php incluido OK");
    
    // PASO 2: Probar conexión a BD
    clean_log("PASO 2: Conectando a BD...");
    $pdo = db();
    clean_log("Conexión a BD OK");
    
    // PASO 3: Probar autenticación
    clean_log("PASO 3: Probando autenticación...");
    $user = require_user();
    clean_log("Autenticación OK - Usuario ID: " . $user['id']);
    
    // PASO 4: Probar entrada JSON
    clean_log("PASO 4: Probando entrada JSON...");
    $input = json_input();
    clean_log("Entrada JSON OK: " . json_encode($input));
    
    // PASO 5: Validar file_id
    clean_log("PASO 5: Validando file_id...");
    $fileId = $input['file_id'] ?? null;
    if (!$fileId) {
        clean_log("ERROR: file_id faltante");
        json_error('file_id requerido');
    }
    clean_log("file_id validado: $fileId");
    
    // PASO 6: Buscar archivo en BD
    clean_log("PASO 6: Buscando archivo en BD...");
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    $fileDb = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fileDb) {
        clean_log("ERROR: Archivo no encontrado");
        json_error('Archivo no encontrado');
    }
    clean_log("Archivo encontrado: " . $fileDb['original_filename']);
    
    // PASO 7: Probar configuración de OpenAI
    clean_log("PASO 7: Probando configuración de OpenAI...");
    $providerId = 1; // OpenAI provider ID
    $stmt = $pdo->prepare("SELECT * FROM ai_providers WHERE id = ?");
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        clean_log("ERROR: Provider OpenAI no encontrado");
        json_error('Provider OpenAI no encontrado');
    }
    clean_log("Provider encontrado: " . $provider['name']);
    
    // PASO 8: Probar obtención de API key
    clean_log("PASO 8: Obteniendo API key...");
    $apiKeyPlain = get_api_key_for($user['id'], 'openai', $pdo);
    if (!$apiKeyPlain) {
        clean_log("ERROR: API key no encontrada");
        json_error('API key de OpenAI no encontrada');
    }
    clean_log("API key obtenida: " . substr($apiKeyPlain, 0, 10) . "...");
    
    // PASO 9: Probar ops_json
    clean_log("PASO 9: Probando ops_json...");
    $ops = json_decode($provider['ops_json'], true);
    if (!$ops) {
        clean_log("ERROR: ops_json no válido");
        json_error('ops_json no válido');
    }
    clean_log("ops_json cargado OK - operaciones: " . count($ops['multi']));
    
    clean_log("=== DIAGNÓSTICO COMPLETADO EXITOSAMENTE ===");
    
    json_out([
        'ok' => true,
        'message' => 'Diagnóstico completado exitosamente',
        'user_id' => $user['id'],
        'file_id' => $fileId,
        'file_name' => $fileDb['original_filename'],
        'provider' => $provider['name'],
        'ops_count' => count($ops['multi'])
    ]);
    
} catch (Exception $e) {
    clean_log("ERROR FATAL: " . $e->getMessage());
    clean_log("Stack trace: " . $e->getTraceAsString());
    json_error('Error en diagnóstico: ' . $e->getMessage());
}
?>
