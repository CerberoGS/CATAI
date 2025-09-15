<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// Función para detectar tipo de archivo
function detectFileType($mimeType, $filePath) {
    if (strpos($mimeType, 'image/') === 0) {
        return 'image';
    } elseif (strpos($mimeType, 'application/pdf') === 0) {
        return 'pdf';
    } elseif (strpos($mimeType, 'text/') === 0) {
        return 'text';
    }
    return 'unknown';
}

// Función helper robusta para operaciones DB con reconexión automática
function dbExecute(&$pdo, $sql, $params = []) {
    $maxRetries = 3;
    $retryDelay = 100; // ms
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            // Verificar conexión antes de cada operación
            $pdo->query('SELECT 1');
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Log de éxito si fue después de una reconexión
            if ($attempt > 1) {
                error_log("DB operación exitosa después de $attempt intentos");
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            // Si es "MySQL server has gone away" y no es el último intento
            if (strpos($e->getMessage(), 'MySQL server has gone away') !== false && $attempt < $maxRetries) {
                // Log del intento de reconexión
                error_log("DB reconexión intento $attempt: " . $e->getMessage());
                
                // Log adicional para debugging
                error_log("DB reconexión intento $attempt para consulta: " . substr($sql, 0, 100) . "...");
                
                // Recrear conexión PDO y actualizar la referencia
                global $CONFIG;
                $dsn = "mysql:host={$CONFIG['DB_HOST']};dbname={$CONFIG['DB_NAME']};charset=utf8mb4";
                $pdo = new PDO($dsn, $CONFIG['DB_USER'], $CONFIG['DB_PASS'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                
                // Esperar antes del siguiente intento
                usleep($retryDelay * 1000);
                $retryDelay *= 2; // Backoff exponencial
                
                continue;
            }
            
            // Si no es "MySQL server has gone away" o es el último intento, relanzar
            throw $e;
        }
    }
    
    throw new Exception("No se pudo ejecutar la consulta después de $maxRetries intentos");
}

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
    
    // Configurar límites extendidos para archivos grandes
    set_time_limit(600); // 10 minutos
    ini_set('max_execution_time', 600);
    ini_set('memory_limit', '512M');

    // Capturar cualquier output no deseado
    ob_start();

    // Autenticación
    $user = require_user();
    
    // Parámetros - leer del JSON body si es POST
    $input = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    }
    
    if (!$input || !isset($input['file_id'])) {
        json_error('Parámetro file_id requerido');
    }
    
    $fileId = (int)$input['file_id'];
    
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
        // Buscar archivo por filename si no se encuentra por ID
        $stmt = dbExecute($pdo, 'SELECT kf.* FROM knowledge_files kf 
            JOIN knowledge_base kb ON kf.original_filename = kb.source_file 
            WHERE kf.id = ? AND kf.user_id = ?', [$fileId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$kf) {
            json_error('Archivo no encontrado o no tienes permisos');
        }
    }
    
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado: ' . $filePath);
    }
    
    // 3) Detectar tipo de archivo y obtener configuración del usuario
    $fileType = detectFileType($kf['mime_type'], $filePath);
    
    // Obtener configuración de IA del usuario
    $stmt = dbExecute($pdo, 'SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?', [$user['id']]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $userSettings['ai_provider'] ?? 'openai';
    $aiModel = $userSettings['ai_model'] ?? 'gpt-4o';
    
    // Verificar/crear OpenAI file ID (solo para PDF/Texto)
    $openaiFileId = $kf['openai_file_id'] ?? null;
    
    if ($fileType === 'pdf' || $fileType === 'text') {
        if (!$openaiFileId) {
            // Subir archivo a OpenAI
            $openaiKey = get_api_key_for($user['id'], 'openai');
            if (!$openaiKey) {
                json_error('OpenAI API key no configurada. Ve a Configuración para configurar tu API key de OpenAI.');
            }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.openai.com/v1/files',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 300, // 5 minutos para subir archivo
            CURLOPT_CONNECTTIMEOUT => 30, // 30 segundos para conectar
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openaiKey,
                'Content-Type: multipart/form-data'
            ],
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile($filePath, $kf['mime_type'], $kf['original_filename']),
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
        $openaiFileId = $fileData['id'];
        
        // Guardar OpenAI file ID
        dbExecute($pdo, '
            UPDATE knowledge_files 
            SET openai_file_id = ?, openai_file_uploaded_at = NOW() 
            WHERE id = ?', [$openaiFileId, $kf['id']]);
    } else {
        // Verificar que el archivo sigue existiendo en OpenAI
        $openaiKey = get_api_key_for($user['id'], 'openai');
        if (!$openaiKey) {
            json_error('OpenAI API key no configurada');
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.openai.com/v1/files/$openaiFileId",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60, // 1 minuto para verificar archivo
            CURLOPT_CONNECTTIMEOUT => 30, // 30 segundos para conectar
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openaiKey
            ]
        ]);
        
        $verifyResponse = curl_exec($ch);
        $verifyCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($verifyCode !== 200) {
            // Archivo no existe en OpenAI, subirlo de nuevo
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.openai.com/v1/files',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: multipart/form-data'
                ],
                CURLOPT_POSTFIELDS => [
                    'file' => new CURLFile($filePath, $kf['mime_type'], $kf['original_filename']),
                    'purpose' => 'assistants'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                json_error('Error re-subiendo archivo a OpenAI: ' . $response);
            }
            
            $fileData = json_decode($response, true);
            $openaiFileId = $fileData['id'];
            
            // Actualizar OpenAI file ID
            dbExecute($pdo, 'UPDATE knowledge_files SET openai_file_verified_at = NOW() WHERE id = ?', [$kf['id']]);
        }
    }
    
    // 4) Llamar a IA según tipo de archivo y proveedor configurado
    if ($fileType === 'image') {
        // Para imágenes, usar gpt-4-vision-preview directamente
        if ($aiProvider !== 'openai') {
            json_error('Para análisis de imágenes, solo se soporta OpenAI. Cambia tu proveedor de IA a OpenAI en Configuración.');
        }
        
        $openaiKey = get_api_key_for($user['id'], 'openai');
        if (!$openaiKey) {
            json_error('OpenAI API key no configurada. Ve a Configuración para configurar tu API key de OpenAI.');
        }
        
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
                                'url' => 'data:' . $kf['mime_type'] . ';base64,' . $imageData
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
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openaiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        
    } elseif ($fileType === 'pdf' || $fileType === 'text') {
        // Para PDF/Texto, usar el proveedor configurado por el usuario
        if ($aiProvider === 'gemini') {
            // Usar Gemini para PDF/Texto
            $geminiKey = get_api_key_for($user['id'], 'gemini');
            if (!$geminiKey) {
                json_error('Gemini API key no configurada. Ve a Configuración para configurar tu API key de Gemini.');
            }
            
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
                    'maxOutputTokens' => 1800,
                    'responseMimeType' => 'application/json'
                ],
                'safetySettings' => [
                    [
                        'category' => 'HARM_CATEGORY_HARASSMENT',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                    ],
                    [
                        'category' => 'HARM_CATEGORY_HATE_SPEECH',
                        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                    ]
                ]
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $geminiKey,
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
            // Usar OpenAI para PDF/Texto
        
        $openaiKey = get_api_key_for($user['id'], 'openai');
        if (!$openaiKey) {
            json_error('OpenAI API key no configurada. Ve a Configuración para configurar tu API key de OpenAI.');
        }
        
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
                            'file_id' => $openaiFileId
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
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            
        } else {
            json_error('Proveedor de IA no soportado: ' . $aiProvider . '. Solo se soportan OpenAI y Gemini.');
        }
        
    } else {
        json_error('Tipo de archivo no soportado: ' . $fileType . '. Solo se soportan PDF, texto e imágenes.');
    }
    
    $startTime = microtime(true);
    error_log("Iniciando llamada a {$aiProvider} API para archivo: " . $kf['original_filename']);
    
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    error_log("Llamada a {$aiProvider} API completada en {$latency}ms, HTTP: $httpCode");
    
    curl_close($ch);
    
    if ($curlError) {
        // Manejar errores de timeout específicamente
        if (strpos($curlError, 'timeout') !== false) {
            json_error('Timeout en llamada a ' . $aiProvider . ' API. El archivo puede ser muy grande o la API está lenta. Intenta con un archivo más pequeño.');
        }
        json_error('Error cURL: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        json_error('No fue posible hacer la consulta a la IA. Error (HTTP ' . $httpCode . '): ' . $response . '. Revisa tu API key o cambia de IA y vuelve a probar.');
    }
    
    $responseData = json_decode($response, true);
    
    // 5) Procesar respuesta según proveedor y tipo de archivo
    if ($aiProvider === 'gemini') {
        // Procesar respuesta de Gemini
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
            // Si no es JSON válido, crear estructura básica
            $summaryData = [
                'resumen' => $aiResponse,
                'puntos_clave' => ['Análisis completado con Gemini'],
                'estrategias' => ['Revisar contenido para estrategias específicas'],
                'gestion_riesgo' => ['Evaluar riesgos basados en análisis'],
                'recomendaciones' => ['Seguir recomendaciones del análisis']
            ];
        }
        
    } elseif ($fileType === 'image') {
        // Para imágenes con OpenAI, la respuesta viene en formato chat/completions
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
        // Para PDF/Texto con OpenAI, la respuesta viene en formato responses
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
    
    // 6) Calcular métricas
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    if ($aiProvider === 'gemini') {
        $usage = $responseData['usageMetadata'] ?? [];
        $totalTokens = $usage['totalTokenCount'] ?? 0;
        $cost = $totalTokens * 0.00000075; // Aproximado para gemini-1.5-flash
    } else {
        $usage = $responseData['usage'] ?? [];
        $totalTokens = $usage['total_tokens'] ?? 0;
        $cost = $totalTokens * 0.000005; // Aproximado para gpt-4o
    }
    $extractedItems = count($summaryData['puntos_clave'] ?? []) + 
                     count($summaryData['estrategias'] ?? []) + 
                     count($summaryData['gestion_riesgo'] ?? []) + 
                     count($summaryData['recomendaciones'] ?? []);
    
    // 7) GUARDADO REAL EN BASE DE DATOS
    try {
        // Guardar en knowledge_base
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
        dbExecute($pdo, '
            UPDATE knowledge_files 
            SET extraction_status = ?, extracted_items = ?, openai_file_id = ?, updated_at = NOW()
            WHERE id = ?
        ', ['completed', $extractedItems, $openaiFileId, $kf['id']]);
        
        // Log del guardado exitoso
        error_log("Knowledge guardado exitosamente: ID $knowledgeId, archivo {$kf['original_filename']}");
        
    } catch (Exception $e) {
        error_log("Error guardando knowledge: " . $e->getMessage());
        json_error('Error guardando datos en base de datos: ' . $e->getMessage());
    }
    
    // 8) Respuesta exitosa
    json_ok([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'file' => [
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_type' => $kf['file_type'],
            'size_mb' => round($kf['file_size'] / 1024 / 1024, 2)
        ],
        'ai' => [
            'provider' => $aiProvider,
            'model' => $aiModel,
            'openai_file_id' => $openaiFileId
        ],
        'summary' => $summaryData,
        '🔍 DEBUG: INFORMACIÓN DE SUBIDA DE ARCHIVO' => [
            'action' => $kf['openai_file_id'] ? 'using_existing_file' : 'uploaded_new_file',
            'openai_file_id' => $openaiFileId,
            'verify_response_code' => $verifyCode ?? 200,
            'verify_response' => $verifyResponse ?? 'N/A'
        ],
        '🔍 DEBUG: INFORMACIÓN DE LLAMADA A API' => [
            'provider' => $aiProvider,
            'endpoint' => $aiProvider === 'gemini' ? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent' : 'https://api.openai.com/v1/responses',
            'payload' => $payload,
            'response_code' => $httpCode,
            'response_body' => $response,
            'curl_error' => $curlError
        ],
        '📊 TRAZABILIDAD: MÉTRICAS DE EXTRACCIÓN' => [
            'provider' => $aiProvider,
            'latency_ms' => $latency,
            'total_tokens' => $totalTokens,
            'cost_usd' => $cost,
            'model' => $aiModel,
            'response_id' => $responseData['id'] ?? $responseData['candidates'][0]['finishReason'] ?? 'N/A',
            'extracted_items' => $extractedItems,
            'status' => 'completed'
        ],
        '📋 GUARDADO REAL: DATOS GUARDADOS EN KNOWLEDGE_BASE' => [
            'tabla' => 'knowledge_base',
            'operacion' => 'INSERT',
            'knowledge_id' => $knowledgeId,
            'datos' => $knowledgeData,
            'status' => 'GUARDADO EXITOSAMENTE'
        ],
        '📋 GUARDADO REAL: DATOS ACTUALIZADOS EN KNOWLEDGE_FILES' => [
            'tabla' => 'knowledge_files',
            'operacion' => 'UPDATE',
            'where' => "id = $fileId",
            'extraction_status' => 'completed',
            'extracted_items' => $extractedItems,
            'openai_file_id' => $openaiFileId,
            'status' => 'ACTUALIZADO EXITOSAMENTE'
        ],
        'leak' => ob_get_clean()
    ]);

} catch (Exception $e) {
    json_error('Error interno: ' . $e->getMessage());
}
?>
