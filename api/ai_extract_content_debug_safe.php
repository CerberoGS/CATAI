<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// Aplicar CORS
apply_cors();

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido');
}

// Obtener input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['file_id'])) {
    json_error('file_id requerido');
}

// Autenticación
$user = require_user();

$fileId = (int)$input['file_id'];

// Log de inicio
error_log("=== INICIO DIAGNÓSTICO EXTRACCIÓN ===");
error_log("Usuario: {$user['id']}, File ID: $fileId");

try {
    // 1) Verificar configuración del usuario
    error_log("Paso 1: Verificando configuración del usuario...");
    $stmt = dbExecute($pdo, 'SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?', [$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_error('Configuración de usuario no encontrada');
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o';
    
    error_log("Configuración encontrada: Provider=$aiProvider, Model=$aiModel");
    
    // 2) Verificar archivo en base de datos
    error_log("Paso 2: Verificando archivo en base de datos...");
    $stmt = dbExecute($pdo, 'SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?', [$fileId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_error('Archivo no encontrado o no tienes permisos');
    }
    
    error_log("Archivo encontrado: {$kf['original_filename']}, Tipo: {$kf['mime_type']}");
    
    // 3) Verificar archivo físico
    error_log("Paso 3: Verificando archivo físico...");
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado: ' . $filePath);
    }
    
    $fileSize = filesize($filePath);
    error_log("Archivo físico encontrado: $filePath, Tamaño: " . round($fileSize / 1024 / 1024, 2) . " MB");
    
    // 4) Verificar API key
    error_log("Paso 4: Verificando API key...");
    $apiKey = get_api_key_for($user['id'], $aiProvider);
    
    if (!$apiKey) {
        json_error("API key de $aiProvider no configurada");
    }
    
    error_log("API key encontrada: " . substr($apiKey, 0, 10) . "...");
    
    // 5) Simular lectura de archivo (sin procesar)
    error_log("Paso 5: Simulando lectura de archivo...");
    $fileContent = file_get_contents($filePath);
    $contentLength = strlen($fileContent);
    
    error_log("Contenido leído: $contentLength caracteres");
    
    // 6) Simular payload (sin enviar)
    error_log("Paso 6: Simulando payload...");
    
    if ($aiProvider === 'gemini') {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => 'Test de análisis - ' . substr($fileContent, 0, 100) . '...'
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 100
            ]
        ];
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';
    } else {
        $payload = [
            'model' => $aiModel,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Test de análisis - ' . substr($fileContent, 0, 100) . '...'
                ]
            ],
            'max_tokens' => 100
        ];
        $endpoint = 'https://api.openai.com/v1/chat/completions';
    }
    
    error_log("Payload simulado: " . json_encode($payload));
    error_log("Endpoint: $endpoint");
    
    // 7) Test de conectividad (sin enviar payload completo)
    error_log("Paso 7: Test de conectividad...");
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10, // Solo 10 segundos para test
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    curl_close($ch);
    
    error_log("Test de conectividad completado: HTTP $httpCode, Latencia: {$latency}ms");
    
    if ($curlError) {
        error_log("Error cURL: $curlError");
    }
    
    // 8) Respuesta de diagnóstico
    json_ok([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'diagnostico' => [
            'usuario' => $user['id'],
            'file_id' => $fileId,
            'archivo' => [
                'nombre' => $kf['original_filename'],
                'tipo' => $kf['mime_type'],
                'tamaño_mb' => round($fileSize / 1024 / 1024, 2),
                'ruta' => $filePath,
                'existe' => file_exists($filePath)
            ],
            'configuracion' => [
                'provider' => $aiProvider,
                'modelo' => $aiModel,
                'api_key_configurada' => !empty($apiKey)
            ],
            'conectividad' => [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'latencia_ms' => $latency,
                'curl_error' => $curlError,
                'response_length' => strlen($response)
            ],
            'contenido' => [
                'longitud_caracteres' => $contentLength,
                'muestra' => substr($fileContent, 0, 200) . '...'
            ]
        ],
        'siguiente_paso' => 'Si todo está OK, el problema está en el procesamiento completo del archivo'
    ]);
    
} catch (Exception $e) {
    error_log("Error en diagnóstico: " . $e->getMessage());
    json_error('Error en diagnóstico: ' . $e->getMessage());
}
