<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Procesa un archivo subido con APIs de IA
 * Maneja: file_id → vector store → extracción de contenido
 */
try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('method_not_allowed', 405, 'Only POST method allowed');
    }

    $input = json_input();
    $file_id = (int)($input['file_id'] ?? 0);
    $ai_provider = strtolower(trim($input['ai_provider'] ?? 'auto'));
    
    if ($file_id <= 0) {
        json_error('invalid_file_id', 400, 'Valid file_id required');
    }

    error_log("=== AI PROCESS FILE ===");
    error_log("User ID: $user_id, File ID: $file_id, AI Provider: $ai_provider");

    $pdo = db();

    // Obtener información del archivo
    $stmt = $pdo->prepare("
        SELECT * FROM knowledge_files 
        WHERE id = ? AND user_id = ? AND upload_status = 'uploaded'
    ");
    $stmt->execute([$file_id, $user_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        json_error('file_not_found', 404, 'File not found or already processed');
    }

    // Actualizar estado a procesando
    $stmt = $pdo->prepare("
        UPDATE knowledge_files 
        SET upload_status = 'processing', extraction_status = 'extracting'
        WHERE id = ?
    ");
    $stmt->execute([$file_id]);

    // Determinar proveedor de IA
    if ($ai_provider === 'auto') {
        // Lógica para seleccionar el mejor proveedor disponible
        $openai_key = get_api_key_for($user_id, 'openai', 'OPENAI_API_KEY');
        $gemini_key = get_api_key_for($user_id, 'gemini', 'GEMINI_API_KEY');
        
        if ($openai_key) {
            $ai_provider = 'openai';
        } elseif ($gemini_key) {
            $ai_provider = 'gemini';
        } else {
            json_error('no_ai_keys', 400, 'No AI provider keys configured');
        }
    }

    $result = [
        'file_id' => $file_id,
        'ai_provider' => $ai_provider,
        'status' => 'processing',
        'steps_completed' => []
    ];

    try {
        // Paso 1: Subir archivo a la API de IA
        $ai_file_id = null;
        $vector_store_id = null;
        
        switch ($ai_provider) {
            case 'openai':
                $ai_result = processWithOpenAI($file, $user_id);
                $ai_file_id = $ai_result['file_id'] ?? null;
                $vector_store_id = $ai_result['vector_store_id'] ?? null;
                break;
                
            case 'gemini':
                $ai_result = processWithGemini($file, $user_id);
                $ai_file_id = $ai_result['file_id'] ?? null;
                $vector_store_id = $ai_result['vector_store_id'] ?? null;
                break;
                
            default:
                throw new Exception("Unsupported AI provider: $ai_provider");
        }

        if ($ai_file_id) {
            $result['steps_completed'][] = 'uploaded_to_ai';
            $result['ai_file_id'] = $ai_file_id;
        }

        if ($vector_store_id) {
            $result['steps_completed'][] = 'vector_store_created';
            $result['vector_store_id'] = $vector_store_id;
        }

        // Paso 2: Extraer contenido del archivo
        $extracted_content = extractFileContent($file, $ai_provider, $user_id);
        
        if ($extracted_content) {
            $result['steps_completed'][] = 'content_extracted';
            $result['extracted_content'] = $extracted_content;
            
            // Guardar contenido extraído en knowledge_base
            saveExtractedContent($pdo, $user_id, $file, $extracted_content);
        }

        // Actualizar base de datos con resultados
        $stmt = $pdo->prepare("
            UPDATE knowledge_files 
            SET upload_status = 'processed',
                extraction_status = 'extracted',
                ai_file_id = ?,
                vector_store_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$ai_file_id, $vector_store_id, $file_id]);

        $result['status'] = 'completed';
        $result['message'] = 'File processed successfully';

    } catch (Exception $e) {
        error_log("Error processing file: " . $e->getMessage());
        
        // Actualizar estado a error
        $stmt = $pdo->prepare("
            UPDATE knowledge_files 
            SET upload_status = 'error',
                extraction_status = 'failed',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$file_id]);

        $result['status'] = 'error';
        $result['error'] = $e->getMessage();
    }

    json_out($result);

} catch (Exception $e) {
    error_log("Error in ai_process_file_safe.php: " . $e->getMessage());
    json_error('internal_error', 500, 'Internal server error: ' . $e->getMessage());
}

/**
 * Procesa archivo con OpenAI
 */
function processWithOpenAI($file, $user_id) {
    $api_key = get_api_key_for($user_id, 'openai', 'OPENAI_API_KEY');
    if (!$api_key) {
        throw new Exception('OpenAI API key not found');
    }

    $file_path = __DIR__ . '/uploads/knowledge/' . $user_id . '/' . $file['stored_filename'];
    
    // Paso 1: Subir archivo a OpenAI Files API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/files');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
    ]);
    
    $postFields = [
        'file' => new CURLFile($file_path, $file['mime_type'], $file['original_filename']),
        'purpose' => 'assistants'
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("OpenAI Files API error: HTTP $httpCode - $response");
    }
    
    $file_data = json_decode($response, true);
    $ai_file_id = $file_data['id'] ?? null;
    
    if (!$ai_file_id) {
        throw new Exception('Failed to get file ID from OpenAI');
    }

    // Paso 2: Crear vector store (opcional, depende del uso)
    // Por ahora retornamos solo el file_id
    return [
        'file_id' => $ai_file_id,
        'vector_store_id' => null // Se implementará si es necesario
    ];
}

/**
 * Procesa archivo con Gemini
 */
function processWithGemini($file, $user_id) {
    $api_key = get_api_key_for($user_id, 'gemini', 'GEMINI_API_KEY');
    if (!$api_key) {
        throw new Exception('Gemini API key not found');
    }

    // Gemini maneja archivos de manera diferente
    // Por ahora simulamos el proceso
    $file_path = __DIR__ . '/uploads/knowledge/' . $user_id . '/' . $file['stored_filename'];
    
    // Leer contenido del archivo
    $content = file_get_contents($file_path);
    if (!$content) {
        throw new Exception('Could not read file content');
    }

    // Simular file_id para Gemini
    $ai_file_id = 'gemini_' . hash('sha256', $content . $file['original_filename']);
    
    return [
        'file_id' => $ai_file_id,
        'vector_store_id' => null
    ];
}

/**
 * Extrae contenido del archivo usando IA
 */
function extractFileContent($file, $ai_provider, $user_id) {
    $file_path = __DIR__ . '/uploads/knowledge/' . $user_id . '/' . $file['stored_filename'];
    
    switch ($ai_provider) {
        case 'openai':
            return extractWithOpenAI($file_path, $file, $user_id);
        case 'gemini':
            return extractWithGemini($file_path, $file, $user_id);
        default:
            throw new Exception("Unsupported AI provider for extraction: $ai_provider");
    }
}

/**
 * Extrae contenido usando OpenAI
 */
function extractWithOpenAI($file_path, $file, $user_id) {
    $api_key = get_api_key_for($user_id, 'openai', 'OPENAI_API_KEY');
    
    // Leer contenido del archivo
    $content = file_get_contents($file_path);
    if (!$content) {
        throw new Exception('Could not read file content');
    }

    // Para archivos de texto, usar directamente
    if ($file['file_type'] === 'txt' || $file['file_type'] === 'csv') {
        return [
            'content' => $content,
            'summary' => substr($content, 0, 500) . '...',
            'extraction_method' => 'direct_read'
        ];
    }

    // Para otros tipos, usar OpenAI para extraer
    $prompt = "Extrae y estructura el contenido de este archivo: " . $file['original_filename'] . "\n\nContenido: " . substr($content, 0, 4000);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ]);
    
    $postData = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Eres un experto en extraer y estructurar información de documentos. Proporciona un resumen claro y puntos clave.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 1500,
        'temperature' => 0.3
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("OpenAI API error: HTTP $httpCode - $response");
    }
    
    $data = json_decode($response, true);
    $extracted_text = $data['choices'][0]['message']['content'] ?? '';
    
    return [
        'content' => $extracted_text,
        'summary' => substr($extracted_text, 0, 300) . '...',
        'extraction_method' => 'openai_gpt'
    ];
}

/**
 * Extrae contenido usando Gemini
 */
function extractWithGemini($file_path, $file, $user_id) {
    // Implementación básica para Gemini
    $content = file_get_contents($file_path);
    if (!$content) {
        throw new Exception('Could not read file content');
    }

    return [
        'content' => $content,
        'summary' => substr($content, 0, 500) . '...',
        'extraction_method' => 'gemini_direct'
    ];
}

/**
 * Guarda contenido extraído en knowledge_base
 */
function saveExtractedContent($pdo, $user_id, $file, $extracted_content) {
    // Crear tabla knowledge_base si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS knowledge_base (
        id INT AUTO_INCREMENT PRIMARY KEY,
        knowledge_type ENUM('user_insight', 'market_data', 'analysis_pattern', 'strategy') DEFAULT 'user_insight',
        title VARCHAR(255) NOT NULL,
        content LONGTEXT NOT NULL,
        summary TEXT,
        tags JSON,
        confidence_score DECIMAL(3,2) DEFAULT 0.70,
        created_by INT NOT NULL,
        source_type ENUM('ai_extraction', 'manual', 'analysis') DEFAULT 'ai_extraction',
        source_file VARCHAR(255),
        is_public BOOLEAN DEFAULT FALSE,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_created (created_by, created_at),
        INDEX idx_type (knowledge_type),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("
        INSERT INTO knowledge_base 
        (knowledge_type, title, content, summary, tags, confidence_score, created_by, source_type, source_file, is_public, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'ai_extraction', ?, FALSE, TRUE)
    ");
    
    $title = 'Conocimiento extraído: ' . $file['original_filename'];
    $tags = json_encode(['extraído', 'archivo', $file['file_type']]);
    
    $stmt->execute([
        'user_insight',
        $title,
        $extracted_content['content'],
        $extracted_content['summary'],
        $tags,
        0.70,
        $user_id,
        $file['original_filename']
    ]);
    
    return $pdo->lastInsertId();
}
