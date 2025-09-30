<?php declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';
require_once 'run_op_safe.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }

    // Validación de método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }

    // Obtener prompt personalizado del POST
    $input = json_decode(file_get_contents('php://input'), true);
    $customPrompt = $input['prompt'] ?? null;

    $pdo = db();

    // 1. Obtener el Vector Store del usuario
    $vsStmt = $pdo->prepare("
        SELECT external_id, name 
        FROM ai_vector_stores 
        WHERE owner_user_id = ? AND status = 'ready'
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $vsStmt->execute([$user_id]);
    $vs = $vsStmt->fetch();
    
    if (!$vs) {
        json_error('no_vector_store', 404, 'No hay Vector Store activo para el usuario');
    }

    // 2. Obtener provider_id de OpenAI
    $providerStmt = $pdo->prepare("SELECT id FROM ai_providers WHERE slug = 'openai'");
    $providerStmt->execute();
    $provider = $providerStmt->fetch();
    
    if (!$provider) {
        json_error('provider_not_found', 500, 'Proveedor OpenAI no encontrado');
    }

    // 3. Preparar prompt por defecto o usar el personalizado
    $defaultPrompt = "Resume en 5 bullets la información más relevante de los archivos del vector store del usuario. Incluye conceptos clave, datos importantes y conclusiones principales.";
    $prompt = $customPrompt ?: $defaultPrompt;

    // 4. Crear Assistant para File Search
    error_log("Creando assistant para VS: " . $vs['external_id']);
    $createResult = runOp($provider['id'], 'assistant.create', [
        'VS_ID' => $vs['external_id']
    ]);
    
    if (!$createResult['ok']) {
        json_error('assistant_creation_failed', 500, 'Error creando assistant: ' . ($createResult['error'] ?? 'Error desconocido'));
    }
    
    $assistantId = $createResult['data']['id'] ?? '';
    error_log("Assistant creado: " . $assistantId);

    // 5. Usar runOp para extraer conocimiento del VS
    error_log("Extrayendo conocimiento del VS: " . $vs['external_id'] . " con assistant: " . $assistantId);
    
    // Mostrar la configuración JSON que se está usando
    $opsJson = json_decode($provider['ops_json'], true);
    $extractConfig = $opsJson['multi']['extract.knowledge_from_vs'] ?? 'NO ENCONTRADO';
    
    error_log("CONFIGURACIÓN JSON PARA EXTRACT.KNOWLEDGE_FROM_VS:");
    error_log(json_encode($extractConfig, JSON_PRETTY_PRINT));
    
    $params = [
        'EXTRACT_PROMPT' => $prompt,
        'VS_ID' => $vs['external_id'],
        'ASSISTANT_ID' => $assistantId
    ];
    
    error_log("PARÁMETROS ENVIADOS:");
    error_log(json_encode($params, JSON_PRETTY_PRINT));
    
    $result = runOp($provider['id'], 'extract.knowledge_from_vs', $params);

    error_log("Resultado de extract.knowledge_from_vs: " . json_encode($result));

    if (!$result['ok']) {
        $errorDetails = $result['error'] ?? 'Error desconocido';
        $fullResponse = json_encode($result, JSON_PRETTY_PRINT);
        json_error('extraction_failed', 500, "Error al extraer resumen del Vector Store.\n\nDetalles del error: $errorDetails\n\nRespuesta completa:\n$fullResponse");
    }

    // 5. Procesar respuesta y extraer el resumen
    $summary = '';
    if (isset($result['data']['msgs']['data']) && is_array($result['data']['msgs']['data'])) {
        foreach ($result['data']['msgs']['data'] as $message) {
            if ($message['role'] === 'assistant' && isset($message['content'])) {
                foreach ($message['content'] as $content) {
                    if ($content['type'] === 'text') {
                        $summary .= $content['text']['value'] . "\n";
                    }
                }
            }
        }
    }

    if (empty($summary)) {
        json_error('no_summary', 500, 'No se pudo extraer el resumen del Vector Store');
    }

    // 6. Guardar en knowledge_base
    $insertStmt = $pdo->prepare("
        INSERT INTO knowledge_base (
            user_id, 
            knowledge_type, 
            title, 
            content, 
            summary, 
            confidence_score, 
            source_type, 
            source_reference, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $insertStmt->execute([
        $user_id,
        'vector_store_summary',
        'Resumen del Vector Store - ' . $vs['name'],
        $summary,
        substr($summary, 0, 500) . '...',
        0.85, // Alta confianza para resúmenes de VS
        'vector_store',
        $vs['external_id']
    ]);

    $kbId = $pdo->lastInsertId();

    json_out([
        'ok' => true,
        'message' => 'Resumen extraído exitosamente del Vector Store',
        'vector_store_id' => $vs['external_id'],
        'vector_store_name' => $vs['name'],
        'summary' => $summary,
        'knowledge_base_id' => $kbId,
        'assistant_id' => $assistantId,
        'debug_info' => [
            'vs_id' => $vs['external_id'],
            'assistant_created' => $assistantId,
            'prompt_used' => $prompt,
            'thread_id' => $result['data']['thread']['id'] ?? 'N/A',
            'run_id' => $result['data']['run']['id'] ?? 'N/A',
            'messages_count' => count($result['data']['msgs']['data'] ?? []),
            'extract_config_used' => $extractConfig,
            'parameters_sent' => $params
        ],
        'full_response' => $result['data']
    ]);

} catch (Throwable $e) {
    error_log("Error en extract_summary_from_vs_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
