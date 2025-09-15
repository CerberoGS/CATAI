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

try {
    // 1) Verificar configuración del usuario
    $stmt = dbExecute($pdo, 'SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?', [$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_error('Configuración de usuario no encontrada');
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o';
    
    // 2) Verificar archivo en base de datos
    $stmt = dbExecute($pdo, 'SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?', [$fileId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_error('Archivo no encontrado o no tienes permisos');
    }
    
    // 3) Verificar archivo físico
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado: ' . $filePath);
    }
    
    $fileSize = filesize($filePath);
    
    // 4) Verificar API key
    $apiKey = get_api_key_for($user['id'], $aiProvider);
    
    if (!$apiKey) {
        json_error("API key de $aiProvider no configurada");
    }
    
    // 5) Leer archivo (solo los primeros 1000 caracteres para test)
    $fileContent = file_get_contents($filePath);
    $fileContent = substr($fileContent, 0, 1000); // Solo primeros 1000 caracteres
    $fileContent = preg_replace('/[^\x20-\x7E]/', '', $fileContent); // Solo ASCII
    
    // 6) Test de conectividad simple (sin enviar archivo completo)
    if ($aiProvider === 'gemini') {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => 'Responde solo "OK" si puedes procesar este texto: ' . $fileContent
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 10
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30, // Solo 30 segundos
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        
    } else {
        $payload = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Responde solo "OK" si puedes procesar este texto: ' . $fileContent
                ]
            ],
            'max_tokens' => 10,
            'temperature' => 0.1
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30, // Solo 30 segundos
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
    }
    
    // 7) Ejecutar test
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    curl_close($ch);
    
    // 8) Respuesta de test
    json_ok([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'test' => [
            'usuario' => $user['id'],
            'file_id' => $fileId,
            'archivo' => [
                'nombre' => $kf['original_filename'],
                'tipo' => $kf['file_type'],
                'tamaño_mb' => round($fileSize / 1024 / 1024, 2),
                'muestra' => $fileContent
            ],
            'configuracion' => [
                'provider' => $aiProvider,
                'modelo' => $aiModel,
                'api_key_configurada' => !empty($apiKey)
            ],
            'test_conectividad' => [
                'http_code' => $httpCode,
                'latencia_ms' => $latency,
                'curl_error' => $curlError,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200)
            ]
        ],
        'siguiente_paso' => 'Si este test funciona, el problema está en el procesamiento completo del archivo'
    ]);
    
} catch (Exception $e) {
    json_error('Error en test: ' . $e->getMessage());
}
