<?php declare(strict_types=1);

/**
 * /bolsa/api/list_ai_resources_safe.php
 * 
 * Lista archivos y vector stores que ya están en la IA.
 * Muestra información detallada de lo que ya está subido y procesado.
 */

require_once 'helpers.php';
require_once 'db.php';
require_once 'Crypto_safe.php';

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }

    // Validación de método
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Método no permitido', 405);
    }

    // Parámetro para filtrar archivos sin Vector Store
    $filter = $_GET['filter'] ?? 'all'; // 'all', 'no_vs', 'with_vs'

    $pdo = db();

    // Obtener archivos del usuario con información de IA
    $filesStmt = $pdo->prepare("
        SELECT 
            kf.id,
            kf.original_filename,
            kf.stored_filename,
            kf.openai_file_id,
            kf.file_type,
            kf.file_size,
            kf.mime_type,
            kf.upload_status,
            kf.extraction_status,
            kf.extracted_items,
            kf.last_extraction_started_at,
            kf.last_extraction_finished_at,
            kf.last_extraction_model,
            kf.last_extraction_cost_usd,
            kf.last_extraction_input_tokens,
            kf.last_extraction_output_tokens,
            kf.last_extraction_total_tokens,
            kf.vector_store_id,
            kf.vector_store_local_id,
            kf.vector_status,
            kf.vector_last_indexed_at,
            kf.vector_provider,
            kf.created_at,
            kf.updated_at,
            avs.external_id as vs_external_id,
            avs.name as vs_name,
            avs.status as vs_status,
            avs.bytes_used as vs_bytes_used,
            avs.doc_count as vs_doc_count,
            COALESCE(ap.name, ap_direct.name) as provider_name,
            COALESCE(ap.slug, ap_direct.slug) as provider_slug
        FROM knowledge_files kf
        LEFT JOIN ai_vector_stores avs ON kf.vector_store_local_id = avs.id
        LEFT JOIN ai_providers ap ON avs.provider_id = ap.id
        LEFT JOIN ai_providers ap_direct ON kf.vector_provider = ap_direct.slug
        WHERE kf.user_id = ?
    ");
    
    // Agregar filtro según el parámetro
    if ($filter === 'no_vs') {
        $filesStmt = $pdo->prepare("
            SELECT 
                kf.id,
                kf.original_filename,
                kf.stored_filename,
                kf.openai_file_id,
                kf.file_type,
                kf.file_size,
                kf.mime_type,
                kf.upload_status,
                kf.extraction_status,
                kf.extracted_items,
                kf.last_extraction_started_at,
                kf.last_extraction_finished_at,
                kf.last_extraction_model,
                kf.last_extraction_cost_usd,
                kf.last_extraction_input_tokens,
                kf.last_extraction_output_tokens,
                kf.last_extraction_total_tokens,
                kf.vector_store_id,
                kf.vector_store_local_id,
                kf.vector_status,
                kf.vector_last_indexed_at,
                kf.vector_provider,
                kf.created_at,
                kf.updated_at,
                avs.external_id as vs_external_id,
                avs.name as vs_name,
                avs.status as vs_status,
                avs.bytes_used as vs_bytes_used,
                avs.doc_count as vs_doc_count,
                COALESCE(ap.name, ap_direct.name) as provider_name,
                COALESCE(ap.slug, ap_direct.slug) as provider_slug
            FROM knowledge_files kf
            LEFT JOIN ai_vector_stores avs ON kf.vector_store_local_id = avs.id
            LEFT JOIN ai_providers ap ON avs.provider_id = ap.id
            LEFT JOIN ai_providers ap_direct ON kf.vector_provider = ap_direct.slug
            WHERE kf.user_id = ? 
            AND kf.openai_file_id IS NOT NULL 
            AND kf.openai_file_id != ''
            AND (kf.vector_store_id IS NULL OR kf.vector_store_id = '')
            ORDER BY kf.created_at DESC
        ");
    } elseif ($filter === 'with_vs') {
        $filesStmt = $pdo->prepare("
            SELECT 
                kf.id,
                kf.original_filename,
                kf.stored_filename,
                kf.openai_file_id,
                kf.file_type,
                kf.file_size,
                kf.mime_type,
                kf.upload_status,
                kf.extraction_status,
                kf.extracted_items,
                kf.last_extraction_started_at,
                kf.last_extraction_finished_at,
                kf.last_extraction_model,
                kf.last_extraction_cost_usd,
                kf.last_extraction_input_tokens,
                kf.last_extraction_output_tokens,
                kf.last_extraction_total_tokens,
                kf.vector_store_id,
                kf.vector_store_local_id,
                kf.vector_status,
                kf.vector_last_indexed_at,
                kf.vector_provider,
                kf.created_at,
                kf.updated_at,
                avs.external_id as vs_external_id,
                avs.name as vs_name,
                avs.status as vs_status,
                avs.bytes_used as vs_bytes_used,
                avs.doc_count as vs_doc_count,
                COALESCE(ap.name, ap_direct.name) as provider_name,
                COALESCE(ap.slug, ap_direct.slug) as provider_slug
            FROM knowledge_files kf
            LEFT JOIN ai_vector_stores avs ON kf.vector_store_local_id = avs.id
            LEFT JOIN ai_providers ap ON avs.provider_id = ap.id
            LEFT JOIN ai_providers ap_direct ON kf.vector_provider = ap_direct.slug
            WHERE kf.user_id = ? 
            AND kf.openai_file_id IS NOT NULL 
            AND kf.openai_file_id != ''
            AND kf.vector_store_id IS NOT NULL 
            AND kf.vector_store_id != ''
            ORDER BY kf.created_at DESC
        ");
    } else {
        // Agregar ORDER BY para el caso 'all'
        $filesStmt = $pdo->prepare("
            SELECT 
                kf.id,
                kf.original_filename,
                kf.stored_filename,
                kf.openai_file_id,
                kf.file_type,
                kf.file_size,
                kf.mime_type,
                kf.upload_status,
                kf.extraction_status,
                kf.extracted_items,
                kf.last_extraction_started_at,
                kf.last_extraction_finished_at,
                kf.last_extraction_model,
                kf.last_extraction_cost_usd,
                kf.last_extraction_input_tokens,
                kf.last_extraction_output_tokens,
                kf.last_extraction_total_tokens,
                kf.vector_store_id,
                kf.vector_store_local_id,
                kf.vector_status,
                kf.vector_last_indexed_at,
                kf.vector_provider,
                kf.created_at,
                kf.updated_at,
                avs.external_id as vs_external_id,
                avs.name as vs_name,
                avs.status as vs_status,
                avs.bytes_used as vs_bytes_used,
                avs.doc_count as vs_doc_count,
                COALESCE(ap.name, ap_direct.name) as provider_name,
                COALESCE(ap.slug, ap_direct.slug) as provider_slug
            FROM knowledge_files kf
            LEFT JOIN ai_vector_stores avs ON kf.vector_store_local_id = avs.id
            LEFT JOIN ai_providers ap ON avs.provider_id = ap.id
            LEFT JOIN ai_providers ap_direct ON kf.vector_provider = ap_direct.slug
            WHERE kf.user_id = ?
            ORDER BY kf.created_at DESC
        ");
    }
    
    $filesStmt->execute([$user_id]);
    $files = $filesStmt->fetchAll();

    // Obtener vector stores del usuario con API key
    $vsStmt = $pdo->prepare("
        SELECT 
            avs.id,
            avs.external_id,
            avs.name,
            avs.bytes_used,
            avs.doc_count,
            avs.status,
            avs.created_at,
            avs.updated_at,
            ap.name as provider_name,
            ap.slug as provider_slug,
            ap.ops_json,
            ap.base_url,
            uak.api_key_enc,
            COUNT(avd.id) as document_count
        FROM ai_vector_stores avs
        LEFT JOIN ai_providers ap ON avs.provider_id = ap.id
        LEFT JOIN user_ai_api_keys uak ON ap.id = uak.provider_id AND uak.user_id = ? AND uak.status = 'active'
        LEFT JOIN ai_vector_documents avd ON avs.id = avd.vector_store_id
        WHERE avs.owner_user_id = ?
        GROUP BY avs.id
        ORDER BY avs.created_at DESC
    ");
    $vsStmt->execute([$user_id, $user_id]);
    $vectorStores = $vsStmt->fetchAll();

    // Obtener contenido extraído en knowledge_base
    $kbStmt = $pdo->prepare("
        SELECT 
            kb.id,
            kb.knowledge_type,
            kb.title,
            kb.content,
            kb.summary,
            kb.confidence_score,
            kb.source_type,
            kb.source_file,
            kb.created_at,
            kb.updated_at,
            kb.tags
        FROM knowledge_base kb
        WHERE kb.created_by = ? AND kb.source_type = 'ai_extraction'
        ORDER BY kb.created_at DESC
    ");
    $kbStmt->execute([$user_id]);
    $knowledgeBase = $kbStmt->fetchAll();

    // Obtener estadísticas de uso
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_files,
            SUM(CASE WHEN openai_file_id IS NOT NULL THEN 1 ELSE 0 END) as files_in_ai,
            SUM(CASE WHEN extraction_status = 'completed' THEN 1 ELSE 0 END) as files_extracted,
            SUM(COALESCE(last_extraction_cost_usd, 0)) as total_cost,
            SUM(COALESCE(last_extraction_total_tokens, 0)) as total_tokens
        FROM knowledge_files 
        WHERE user_id = ?
    ");
    $statsStmt->execute([$user_id]);
    $stats = $statsStmt->fetch();

    // Procesar archivos para mostrar información más clara
    $processedFiles = [];
    foreach ($files as $file) {
        $processedFiles[] = [
            'id' => $file['id'],
            'filename' => $file['original_filename'],
            'file_type' => $file['file_type'],
            'file_size_mb' => round($file['file_size'] / 1024 / 1024, 2),
            'upload_status' => $file['upload_status'],
            'extraction_status' => $file['extraction_status'],
            'in_ai' => !empty($file['openai_file_id']),
            'openai_file_id' => $file['openai_file_id'],
            'vector_store_id' => $file['vector_store_id'],
            'vector_store_external_id' => $file['vs_external_id'],
            'vector_status' => $file['vector_status'],
            'extracted_items' => $file['extracted_items'],
            'last_extraction_cost' => $file['last_extraction_cost_usd'],
            'last_extraction_tokens' => $file['last_extraction_total_tokens'],
            'last_extraction_model' => $file['last_extraction_model'],
            'created_at' => $file['created_at'],
            'provider' => $file['provider_name'] ?? ($file['vector_provider'] ?: 'openai')
        ];
    }

    // Procesar vector stores
    $processedVS = [];
    foreach ($vectorStores as $vs) {
        $opsJson = null;
        if (!empty($vs['ops_json'])) {
            $opsJson = json_decode($vs['ops_json'], true);
        }

        // Consultar archivos del VS usando vs.files del ops_json
        $vsFiles = [];
        $vsFilesError = null;
        
        if ($opsJson && isset($opsJson['multi']['vs.files']) && !empty($vs['api_key_enc']) && !empty($vs['base_url'])) {
            $vsFilesConfig = $opsJson['multi']['vs.files'];
            $apiKey = catai_decrypt($vs['api_key_enc']);
            
            // Construir URL usando la configuración del ops_json
            $url = str_replace('{{VS_ID}}', $vs['external_id'], $vsFilesConfig['url_override']);
            $url = str_replace('{{limit}}', '100', $url);
            $url = str_replace('{{after_qs}}', '', $url);
            
            // Preparar headers según la configuración
            $headers = [];
            foreach ($vsFilesConfig['headers'] as $header) {
                $value = str_replace('{{API_KEY}}', $apiKey, $header['value']);
                $headers[] = $header['name'] . ': ' . $value;
            }
            
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers
            ]);
            
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            
            if (!$error && $httpCode === 200) {
                $vsData = json_decode($response, true);
                
                // Log para debug - ver estructura de respuesta
                error_log("VS Files Response: " . json_encode($vsData, JSON_PRETTY_PRINT));
                
                // OpenAI vs.files devuelve {data: [...]} donde cada elemento tiene id, filename, etc.
                $vsFiles = $vsData['data'] ?? [];
                
                // Asegurar que cada archivo tenga los campos necesarios
                foreach ($vsFiles as &$file) {
                    if (!isset($file['filename'])) {
                        $file['filename'] = $file['name'] ?? $file['original_filename'] ?? 'Unknown';
                    }
                    if (!isset($file['file_id'])) {
                        $file['file_id'] = $file['id'] ?? 'Unknown';
                    }
                    // Agregar campo real_filename para el frontend
                    $file['real_filename'] = 'Por buscar en DB';
                }
            } else {
                $vsFilesError = $error ?: "HTTP $httpCode: " . substr($response, 0, 200);
                error_log("VS Files Error: " . $vsFilesError);
            }
        }

        $processedVS[] = [
            'id' => $vs['id'],
            'external_id' => $vs['external_id'],
            'name' => $vs['name'],
            'provider' => $vs['provider_name'],
            'provider_slug' => $vs['provider_slug'],
            'status' => $vs['status'],
            'bytes_used_mb' => round($vs['bytes_used'] / 1024 / 1024, 2),
            'doc_count' => $vs['doc_count'],
            'document_count' => $vs['document_count'],
            'created_at' => $vs['created_at'],
            'updated_at' => $vs['updated_at'],
            'ops_available' => $opsJson ? array_keys($opsJson['multi'] ?? []) : [],
            'vs_files' => $vsFiles,
            'vs_files_count' => count($vsFiles),
            'vs_files_error' => $vsFilesError
        ];
    }

    // Procesar knowledge base
    $processedKB = [];
    foreach ($knowledgeBase as $kb) {
        $processedKB[] = [
            'id' => $kb['id'],
            'type' => $kb['knowledge_type'],
            'title' => $kb['title'],
            'summary' => $kb['summary'],
            'confidence' => $kb['confidence_score'],
            'source_file' => $kb['source_file'],
            'content_length' => strlen($kb['content']),
            'created_at' => $kb['created_at'],
            'tags' => $kb['tags'] ? json_decode($kb['tags'], true) : []
        ];
    }

    json_out([
        'ok' => true,
        'user_id' => $user_id,
        'filter' => $filter,
        'stats' => [
            'total_files' => (int)$stats['total_files'],
            'files_in_ai' => (int)$stats['files_in_ai'],
            'files_extracted' => (int)$stats['files_extracted'],
            'total_cost_usd' => round((float)$stats['total_cost'], 6),
            'total_tokens' => (int)$stats['total_tokens']
        ],
        'files' => $processedFiles,
        'vector_stores' => $processedVS,
        'knowledge_base' => $processedKB
    ]);

} catch (Throwable $e) {
    error_log("Error en list_ai_resources_safe.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    json_error('Error interno del servidor', 500);
}
