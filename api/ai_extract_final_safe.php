<?php
// /api/ai_extract_final_safe.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

/**
 * Asegura que el archivo esté en OpenAI y devuelve el file_id válido
 */
function ensureOpenAIFileId(PDO $pdo, array $kf, array $user, string $filePath): string {
    // Usar datos ya guardados en la BD (más eficiente)
    $mime = $kf['mime_type'];                    // Ya está en la BD
    $origName = $kf['original_filename'];        // Ya está en la BD
    $fileSize = $kf['file_size'];                // Ya está en la BD

    if (!$filePath || !is_readable($filePath)) {
        json_error('Archivo no encontrado o no legible en el servidor.');
    }

    $openaiKey = get_api_key_for($user['id'], 'openai');
    if (!$openaiKey) {
        json_error('OpenAI API key no configurada para este usuario.');
    }

    $id = $kf['openai_file_id'] ?? null;
    $needs = false;

    // Helper HTTP
    $curl = static function(string $url, array $opts) {
        $ch = curl_init();
        curl_setopt_array($ch, $opts + [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 300,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    };

    // Verificar existente
    if ($id) {
        [$code, $body] = $curl("https://api.openai.com/v1/files/{$id}", [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$openaiKey}"],
        ]);

        if ($code === 200) {
            $info = json_decode($body, true) ?: [];
            $status = $info['status'] ?? null;
            $purpose = $info['purpose'] ?? null;
            $bytes = $info['bytes'] ?? null;

            // Si el archivo existe pero hay algo raro, marcamos re-upload
            if (in_array($status, ['error','deleted'], true)
             || $purpose !== 'assistants'
             || ($bytes !== null && (int)$bytes !== (int)$fileSize)) {
                $needs = true;
            } else {
                // OK: persistimos metadata útil
                $stmt = $pdo->prepare('UPDATE knowledge_files
                                     SET openai_file_status=?, openai_file_bytes=?, openai_file_verified_at=NOW()
                                     WHERE id=?');
                $stmt->execute([$status, $bytes, $kf['id']]);
                return $id;
            }
        } else {
            // 404/410 u otros → re-subir
            $needs = true;
        }
    } else {
        $needs = true;
    }

    // Subir/re-subir
    if ($needs) {
        $postFields = [
            'file' => new CURLFile($filePath, $mime, $origName),
            'purpose' => 'assistants',
        ];
        [$code, $body] = $curl('https://api.openai.com/v1/files', [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$openaiKey}"],
            CURLOPT_POSTFIELDS => $postFields,
        ]);

        if ($code !== 200) {
            // Intenta extraer mensaje claro y persiste para debug
            $msg = $body;
            if ($j = json_decode($body, true)) {
                $msg = $j['error']['message'] ?? $body;
            }
            $stmt = $pdo->prepare('UPDATE knowledge_files SET openai_file_last_error=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([substr($msg, 0, 500), $kf['id']]);
            json_error('Error subiendo archivo a OpenAI: ' . $msg);
        }

        $info = json_decode($body, true) ?: [];
        $idNew = $info['id'] ?? null;
        if (!$idNew) {
            json_error('Respuesta inválida de OpenAI al subir el archivo.');
        }

        // Persistir SIEMPRE el nuevo ID y metadata
        $stmt = $pdo->prepare('UPDATE knowledge_files
                             SET openai_file_id=?, openai_file_status=?, openai_file_bytes=?, openai_file_purpose=?,
                                 openai_file_uploaded_at=NOW(), openai_file_verified_at=NOW(), openai_file_last_error=NULL
                             WHERE id=?');
        $stmt->execute([$idNew, $info['status'] ?? null, $info['bytes'] ?? null, $info['purpose'] ?? null, $kf['id']]);

        return $idNew;
    }

    return $id;
}

json_header();

try {
    // 1) Autenticación
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
    }
    
    // 2) Obtener input
    $input = json_input();
    $fileId = (int)($input['file_id'] ?? 0);
    
    if ($fileId <= 0) {
        json_out(['error' => 'file_id-required'], 400);
    }
    
    // 3) Obtener conexión a la base de datos
    $pdo = db();
    
    // 4) Obtener información del archivo
    $stmt = $pdo->prepare('SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId, $userId]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$kf) {
        json_out(['error' => 'file-not-found'], 404);
    }
    
    // 4) Obtener configuración del usuario
    $stmt = $pdo->prepare('SELECT ai_provider, ai_model FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $aiProvider = $settings['ai_provider'] ?? 'openai';
    $aiModel = $settings['ai_model'] ?? 'gpt-4o-mini';
    
    // 5) Obtener API key
    $apiKey = get_api_key_for($userId, $aiProvider);
    if (!$apiKey) {
        json_out(['error' => 'api-key-not-found', 'provider' => $aiProvider], 400);
    }
    
    // 6) Construir path del archivo
    $filePath = __DIR__ . '/uploads/knowledge/' . $userId . '/' . $kf['stored_filename'];
    
    if (!file_exists($filePath)) {
        json_out(['error' => 'file-not-found-physically', 'path' => $filePath], 404);
    }
    
    // 7) Usar datos ya guardados en la BD (más eficiente)
    $fileType = $kf['file_type'];        // Ya está en la BD
    $mimeType = $kf['mime_type'];        // Ya está en la BD
    $originalName = $kf['original_filename']; // Ya está en la BD
    $fileSize = $kf['file_size'];        // Ya está en la BD
    
    // 8) Leer contenido del archivo solo si es necesario (para otros IA o fallback)
    $fileContent = null;
    if ($aiProvider !== 'openai' || $fileType === 'image') {
        $fileContent = file_get_contents($filePath);
        $fileContent = substr($fileContent, 0, 3000); // Solo primeros 3000 caracteres
        $fileContent = preg_replace('/[^\x20-\x7E]/', '', $fileContent); // Solo ASCII
    }
    
    // 9) Variables para tracking de archivos (se asignan en cada case según necesidad)
    $openaiFileId = null;
    $fileAction = 'none';
    
    // 10) Hacer consulta a la IA según el proveedor configurado
    $startTime = microtime(true);
    $ch = curl_init();
    
    // Obtener prompt personalizado del usuario o usar predeterminado
    $stmt = $pdo->prepare('SELECT ai_prompt_ext_conten_file FROM user_settings WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $customPrompt = $userSettings['ai_prompt_ext_conten_file'] ?? $CONFIG['AI_PROMPT_EXTRACT_DEFAULT'];
    
    // Construir prompt solo si es necesario (para otros IA o fallback)
    $prompt = null;
    if ($fileContent) {
        $prompt = $customPrompt . "\n\nCONTENIDO DEL DOCUMENTO:\n" . $fileContent;
    }
    
    switch (strtolower($aiProvider)) {
        case 'gemini':
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => $prompt
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 800
                ]
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'openai':
            // TODOS los archivos necesitan file_id (texto e imágenes)
            $originalFileId = $kf['openai_file_id'] ?? null;
            $openaiFileId = ensureOpenAIFileId($pdo, $kf, $user, $filePath);
            $fileAction = $originalFileId ? 'using_existing_file' : 'uploaded_new_file';
            
            // Determinar si es imagen para la consulta
            $imageTypes = ['image', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'tiff', 'ico'];
            $isImage = in_array($fileType, $imageTypes);
            
            // Construir payload base (común para ambas rutas)
            $payload = [
                'model' => $aiModel,
                'temperature' => 0.3,
                'max_output_tokens' => 800,
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
                ]
            ];
            
            // Construir input según tipo de archivo (SIEMPRE con file_id)
            if ($isImage) {
                // RUTA 1: Imágenes con file_id + OCR
                $payload['model'] = 'gpt-4-vision-preview'; // Cambiar modelo para imágenes
                $payload['input'] = [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_file',
                                'file_id' => $openaiFileId
                            ],
                            [
                                'type' => 'input_text',
                                'text' => $customPrompt . "\n\nAnaliza esta imagen y extrae el contenido de texto usando OCR:"
                            ]
                        ]
                    ]
                ];
            } else {
                // RUTA 2: Archivos de texto con file_id
                $payload['input'] = [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'input_file',
                                'file_id' => $openaiFileId
                            ],
                            [
                                'type' => 'input_text',
                                'text' => $customPrompt
                            ]
                        ]
                    ]
                ];
            }
            
            // Determinar endpoint según tipo de archivo
            $endpoint = $isImage 
                ? 'https://api.openai.com/v1/chat/completions'  // Para imágenes con OCR
                : 'https://api.openai.com/v1/responses';        // Para archivos de texto
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
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
            break;
            
        case 'claude':
            $payload = [
                'model' => $aiModel,
                'max_tokens' => 800,
                'temperature' => 0.3,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ]
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: ' . $apiKey,
                    'Content-Type: application/json',
                    'anthropic-version: 2023-06-01'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'xai':
            $payload = [
                'model' => $aiModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 800,
                'temperature' => 0.3
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.x.ai/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        case 'deepseek':
            $payload = [
                'model' => $aiModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 800,
                'temperature' => 0.3
            ];
            
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.deepseek.com/v1/chat/completions',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);
            break;
            
        default:
            json_out(['error' => 'unsupported-ai-provider', 'provider' => $aiProvider], 400);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $endTime = microtime(true);
    $latency = round(($endTime - $startTime) * 1000, 2);
    
    curl_close($ch);
    
    if ($curlError) {
        json_out(['error' => 'curl-error', 'detail' => $curlError], 500);
    }
    
    if ($httpCode !== 200) {
        json_out(['error' => 'ai-api-error', 'http_code' => $httpCode, 'response' => substr($response, 0, 500)], 500);
    }
    
    // 10) Procesar respuesta de la IA según el proveedor
    $responseData = json_decode($response, true);
    
    switch (strtolower($aiProvider)) {
        case 'gemini':
            if (!isset($responseData['candidates']) || empty($responseData['candidates'])) {
                json_out(['error' => 'gemini-no-content'], 500);
            }
            $aiResponse = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
            break;
            
        case 'openai':
            // Para OpenAI /v1/responses, la estructura es diferente
            if (!isset($responseData['output']) || empty($responseData['output'])) {
                json_out(['error' => 'openai-no-content'], 500);
            }
            $output = $responseData['output'][0];
            if (!isset($output['content']) || empty($output['content'])) {
                json_out(['error' => 'openai-empty-content'], 500);
            }
            $content = $output['content'][0];
            $aiResponse = $content['text'] ?? '';
            break;
            
        case 'xai':
        case 'deepseek':
            if (!isset($responseData['choices']) || empty($responseData['choices'])) {
                json_out(['error' => 'openai-no-content'], 500);
            }
            $aiResponse = $responseData['choices'][0]['message']['content'] ?? '';
            break;
            
        case 'claude':
            if (!isset($responseData['content']) || empty($responseData['content'])) {
                json_out(['error' => 'claude-no-content'], 500);
            }
            $aiResponse = $responseData['content'][0]['text'] ?? '';
            break;
            
        default:
            json_out(['error' => 'unsupported-ai-provider-response'], 500);
    }
    
    if (empty($aiResponse)) {
        json_out(['error' => 'empty-ai-response'], 500);
    }
    
    // 11) Guardar en base de datos
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
        'created_by' => $userId,
        'source_file' => $kf['original_filename'],
        'is_active' => 1
    ];
    
    $stmt = $pdo->prepare('
        INSERT INTO knowledge_base (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_file, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    
    $stmt->execute([
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
    
    // 12) Actualizar knowledge_files
    $stmt = $pdo->prepare('
        UPDATE knowledge_files 
        SET extraction_status = ?, extracted_items = ?, updated_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute(['completed', 1, $kf['id']]);
    
    // 13) Respuesta exitosa
    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'usuario' => [
            'id' => $userId,
            'email' => $user['email']
        ],
        'archivo' => [
            'id' => $kf['id'],
            'nombre' => $originalName,                    // Usar datos de BD
            'tipo' => $fileType,                          // Usar datos de BD
            'mime_type' => $mimeType,                     // Usar datos de BD
            'tamaño_mb' => round($fileSize / 1024 / 1024, 2), // Usar datos de BD
            'openai_file_id' => $openaiFileId,
            'file_action' => $fileAction
        ],
        'ia' => [
            'provider' => $aiProvider,
            'modelo' => $aiModel
        ],
        'prompt_used' => !empty($userSettings['ai_prompt_ext_conten_file']),
        'consulta' => [
            'latencia_ms' => $latency,
            'http_code' => $httpCode,
            'caracteres_procesados' => $fileContent ? strlen($fileContent) : 0,
            'usando_file_id' => !empty($openaiFileId)
        ],
        'resultado' => [
            'resumen' => $aiResponse,
            'puntos_clave' => ['Análisis completado'],
            'estrategias' => ['Revisar contenido para estrategias'],
            'gestion_riesgo' => ['Evaluar riesgos basados en análisis'],
            'recomendaciones' => ['Seguir recomendaciones del análisis']
        ],
        'guardado' => [
            'knowledge_id' => $knowledgeId,
            'status' => 'GUARDADO EXITOSAMENTE'
        ]
    ], 200);
    
} catch (Throwable $e) {
    json_out(['error' => 'extraction-failed', 'detail' => $e->getMessage()], 500);
}
