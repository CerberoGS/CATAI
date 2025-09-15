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
    // 1) Obtener configuración del usuario
    $stmt = dbExecute($pdo, 'SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?', [$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings) {
        json_error('Configuración de usuario no encontrada');
    }
    
    $aiProvider = $settings['ai_provider'] ?? 'auto';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o';
    
    // 2) Obtener información del archivo
    $stmt = dbExecute($pdo, 'SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?', [$fileId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_error('Archivo no encontrado o no tienes permisos');
    }
    
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado: ' . $filePath);
    }
    
    // 3) Obtener API key del usuario
    $apiKey = get_api_key_for($user['id'], $aiProvider);
    if (!$apiKey) {
        json_error("API key de $aiProvider no configurada");
    }
    
    // 4) Leer archivo (solo primeros 2000 caracteres para evitar timeouts)
    $fileContent = file_get_contents($filePath);
    $fileContent = substr($fileContent, 0, 2000); // Solo primeros 2000 caracteres
    $fileContent = preg_replace('/[^\x20-\x7E]/', '', $fileContent); // Solo ASCII
    
    // 5) Hacer consulta simple a la IA
    if ($aiProvider === 'gemini') {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => 'Analiza este texto de trading y da un resumen en 3 puntos clave: ' . $fileContent
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 500
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60, // 1 minuto
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
                    'content' => 'Analiza este texto de trading y da un resumen en 3 puntos clave: ' . $fileContent
                ]
            ],
            'max_tokens' => 500,
            'temperature' => 0.3
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60, // 1 minuto
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
    }
    
    // 6) Ejecutar consulta
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    curl_close($ch);
    
    if ($curlError) {
        json_error('Error cURL: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        json_error('Error en consulta a IA (HTTP ' . $httpCode . '): ' . substr($response, 0, 500));
    }
    
    $responseData = json_decode($response, true);
    
    // 7) Procesar respuesta
    if ($aiProvider === 'gemini') {
        if (!isset($responseData['candidates']) || empty($responseData['candidates'])) {
            json_error('Respuesta de Gemini sin contenido');
        }
        
        $candidate = $responseData['candidates'][0];
        if (!isset($candidate['content']['parts'][0]['text'])) {
            json_error('Contenido de Gemini no encontrado');
        }
        
        $aiResponse = $candidate['content']['parts'][0]['text'];
        
    } else {
        if (!isset($responseData['choices']) || empty($responseData['choices'])) {
            json_error('Respuesta de OpenAI sin contenido');
        }
        
        $choice = $responseData['choices'][0];
        if (!isset($choice['message']['content'])) {
            json_error('Contenido de OpenAI no encontrado');
        }
        
        $aiResponse = $choice['message']['content'];
    }
    
    // 8) Guardar en base de datos
    $summaryData = [
        'resumen' => $aiResponse,
        'puntos_clave' => ['Análisis completado'],
        'estrategias' => ['Revisar contenido para estrategias'],
        'gestion_riesgo' => ['Evaluar riesgos basados en análisis'],
        'recomendaciones' => ['Seguir recomendaciones del análisis']
    ];
    
    $knowledgeData = [
        'knowledge_type' => 'user_insight',
        'title' => $kf['original_filename'] . ' - Análisis IA (' . strtoupper($aiProvider) . ')',
        'content' => json_encode($summaryData),
        'summary' => $aiResponse,
        'tags' => json_encode(['ai_extraction', 'trading', $aiProvider]),
        'confidence_score' => 0.8,
        'created_by' => $user['id'],
        'source_file' => $kf['original_filename'],
        'is_active' => 1
    ];
    
    $stmt = dbExecute($pdo, '
        INSERT INTO knowledge_base (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_file, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ', [
        $knowledgeData['knowledge_type'],
        $knowledgeData['title'],
        $knowledgeData['content'],
        $knowledgeData['summary'],
        $knowledgeData['tags'],
        $knowledgeData['confidence_score'],
        $knowledgeData['created_by'],
        $knowledgeData['source_file'],
        $knowledgeData['is_active']
    ]);
    
    $knowledgeId = $pdo->lastInsertId();
    
    // Actualizar knowledge_files
    dbExecute($pdo, '
        UPDATE knowledge_files 
        SET extraction_status = ?, extracted_items = ?, updated_at = NOW()
        WHERE id = ?
    ', ['completed', 1, $kf['id']]);
    
    // 9) Respuesta exitosa
    json_ok([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'usuario' => $user['id'],
        'archivo' => [
            'id' => $kf['id'],
            'nombre' => $kf['original_filename'],
            'tipo' => $kf['file_type'],
            'tamaño_mb' => round($kf['file_size'] / 1024 / 1024, 2)
        ],
        'ia' => [
            'provider' => $aiProvider,
            'modelo' => $aiModel
        ],
        'consulta' => [
            'latencia_ms' => $latency,
            'http_code' => $httpCode,
            'caracteres_procesados' => strlen($fileContent)
        ],
        'resultado' => $summaryData,
        'guardado' => [
            'knowledge_id' => $knowledgeId,
            'status' => 'GUARDADO EXITOSAMENTE'
        ]
    ]);
    
} catch (Exception $e) {
    json_error('Error: ' . $e->getMessage());
}
