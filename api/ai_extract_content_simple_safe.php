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
    
    // 3) Detectar tipo de archivo
    $fileType = $kf['file_type'] ?? 'unknown';
    $mimeType = $kf['mime_type'] ?? 'unknown';
    
    // 4) Obtener API key del usuario
    $apiKey = get_api_key_for($user['id'], $aiProvider);
    if (!$apiKey) {
        json_error("API key de $aiProvider no configurada");
    }
    
    // 5) Verificar si ya fue consultado con la IA
    $existingFileId = null;
    if ($aiProvider === 'openai' && isset($kf['openai_file_id'])) {
        $existingFileId = $kf['openai_file_id'];
    }
    
    // 6) Subir archivo si es necesario (solo para OpenAI)
    if ($aiProvider === 'openai' && !$existingFileId) {
        // Subir archivo a OpenAI
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/files',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: multipart/form-data'
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($filePath, $mimeType, $kf['original_filename']),
                'purpose' => 'assistants'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            json_error('Error subiendo archivo a OpenAI: ' . $response);
        }
        
        $fileData = json_decode($response, true);
        $existingFileId = $fileData['id'];
        
        // Guardar OpenAI file ID
        dbExecute($pdo, 'UPDATE knowledge_files SET openai_file_id = ? WHERE id = ?', [$existingFileId, $kf['id']]);
    }
    
    // 7) Hacer consulta a la IA
    if ($aiProvider === 'gemini') {
        // Gemini - leer archivo directamente
        $fileContent = file_get_contents($filePath);
        $fileContent = preg_replace('/[^\x20-\x7E]/', '', $fileContent); // Solo ASCII
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => 'Eres un analista de trading experto. Analiza este documento y extrae información valiosa para trading. Responde en español con un resumen ejecutivo, puntos clave, estrategias, gestión de riesgo y recomendaciones. Contenido del documento: ' . $fileContent
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 1800
            ]
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        
    } elseif ($aiProvider === 'openai') {
        // OpenAI - usar file_id
        if ($fileType === 'image') {
            // Para imágenes, usar gpt-4-vision-preview
            $imageData = base64_encode(file_get_contents($filePath));
            
            $payload = [
                'model' => 'gpt-4-vision-preview',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Eres un analista de trading experto. Analiza esta imagen y extrae información valiosa para trading. Responde en español con un resumen ejecutivo, puntos clave, estrategias, gestión de riesgo y recomendaciones.'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => 'data:' . $mimeType . ';base64,' . $imageData
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 1800,
                'temperature' => 0.3
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.openai.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            
        } else {
            // Para PDF/Texto, usar file_id
            $payload = [
                'model' => $aiModel,
                'temperature' => 0.3,
                'max_output_tokens' => 1800,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'knowledge_card',
                        'schema' => [
                            'type' => 'object',
                            'required' => ['resumen', 'puntos_clave', 'estrategias', 'gestion_riesgo', 'recomendaciones'],
                            'properties' => [
                                'resumen' => ['type' => 'string', 'description' => 'Resumen ejecutivo en 2-3 líneas'],
                                'puntos_clave' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '5-8 conceptos clave'],
                                'estrategias' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3-5 estrategias de trading'],
                                'gestion_riesgo' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2-3 puntos de gestión de riesgo'],
                                'recomendaciones' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '2-3 recomendaciones prácticas']
                            ],
                            'additionalProperties' => false
                        ]
                    ]
                ],
                'input' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_text',
                                'text' => 'Eres un analista de trading experto. Analiza este documento y extrae información valiosa para trading. Responde en español siguiendo exactamente el esquema JSON proporcionado. No inventes información que no esté en el documento.'
                            ],
                            [
                                'type' => 'input_file',
                                'file_id' => $existingFileId
                            ]
                        ]
                    ]
                ]
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.openai.com/v1/responses',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
        }
    } else {
        json_error('Proveedor de IA no soportado: ' . $aiProvider);
    }
    
    // 8) Ejecutar consulta
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
        json_error('Error en consulta a IA (HTTP ' . $httpCode . '): ' . $response);
    }
    
    $responseData = json_decode($response, true);
    
    // 9) Procesar respuesta
    if ($aiProvider === 'gemini') {
        if (!isset($responseData['candidates']) || empty($responseData['candidates'])) {
            json_error('Respuesta de Gemini sin contenido');
        }
        
        $candidate = $responseData['candidates'][0];
        if (!isset($candidate['content']['parts'][0]['text'])) {
            json_error('Contenido de Gemini no encontrado');
        }
        
        $aiResponse = $candidate['content']['parts'][0]['text'];
        
        // Intentar decodificar como JSON, si falla usar como texto plano
        $summaryData = json_decode($aiResponse, true);
        if (!$summaryData) {
            $summaryData = [
                'resumen' => $aiResponse,
                'puntos_clave' => ['Análisis completado con Gemini'],
                'estrategias' => ['Revisar contenido para estrategias específicas'],
                'gestion_riesgo' => ['Evaluar riesgos basados en análisis'],
                'recomendaciones' => ['Seguir recomendaciones del análisis']
            ];
        }
        
    } else {
        // OpenAI
        if ($fileType === 'image') {
            if (!isset($responseData['choices']) || empty($responseData['choices'])) {
                json_error('Respuesta de OpenAI sin contenido');
            }
            
            $choice = $responseData['choices'][0];
            if (!isset($choice['message']['content'])) {
                json_error('Contenido de imagen no encontrado');
            }
            
            $summaryData = [
                'resumen' => $choice['message']['content'],
                'puntos_clave' => ['Análisis de imagen completado'],
                'estrategias' => ['Revisar imagen para estrategias específicas'],
                'gestion_riesgo' => ['Evaluar riesgos basados en imagen'],
                'recomendaciones' => ['Seguir análisis visual proporcionado']
            ];
            
        } else {
            if (!isset($responseData['output']) || empty($responseData['output'])) {
                json_error('Respuesta de OpenAI sin contenido');
            }
            
            $output = $responseData['output'][0];
            if (!isset($output['content']) || empty($output['content'])) {
                json_error('Contenido de OpenAI vacío');
            }
            
            $content = $output['content'][0];
            if (!isset($content['text'])) {
                json_error('Texto de respuesta no encontrado');
            }
            
            $summaryData = json_decode($content['text'], true);
            if (!$summaryData) {
                json_error('Error decodificando JSON de respuesta');
            }
        }
    }
    
    // 10) Guardar en base de datos
    $knowledgeData = [
        'knowledge_type' => 'user_insight',
        'title' => $kf['original_filename'] . ' - Análisis IA (' . strtoupper($aiProvider) . ')',
        'content' => json_encode($summaryData),
        'summary' => $summaryData['resumen'] ?? '',
        'tags' => json_encode(['ai_extraction', 'trading', $fileType, $aiProvider]),
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
    $extractedItems = count($summaryData['puntos_clave'] ?? []) + 
                     count($summaryData['estrategias'] ?? []) + 
                     count($summaryData['gestion_riesgo'] ?? []) + 
                     count($summaryData['recomendaciones'] ?? []);
    
    dbExecute($pdo, '
        UPDATE knowledge_files 
        SET extraction_status = ?, extracted_items = ?, updated_at = NOW()
        WHERE id = ?
    ', ['completed', $extractedItems, $kf['id']]);
    
    // 11) Respuesta exitosa
    json_ok([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'usuario' => $user['id'],
        'archivo' => [
            'id' => $kf['id'],
            'nombre' => $kf['original_filename'],
            'tipo' => $fileType,
            'mime_type' => $mimeType,
            'tamaño_mb' => round($kf['file_size'] / 1024 / 1024, 2)
        ],
        'ia' => [
            'provider' => $aiProvider,
            'modelo' => $aiModel,
            'file_id' => $existingFileId
        ],
        'consulta' => [
            'latencia_ms' => $latency,
            'http_code' => $httpCode,
            'tokens' => $responseData['usage']['total_tokens'] ?? $responseData['usageMetadata']['totalTokenCount'] ?? 0
        ],
        'resultado' => $summaryData,
        'guardado' => [
            'knowledge_id' => $knowledgeId,
            'extracted_items' => $extractedItems,
            'status' => 'GUARDADO EXITOSAMENTE'
        ]
    ]);
    
} catch (Exception $e) {
    json_error('Error: ' . $e->getMessage());
}
