<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Vista previa de la solicitud IA (no ejecuta la llamada externa). Útil para validar estrategia y payload.

try {
    // Iniciar buffer para capturar cualquier salida accidental (notices/echo)
    if (!ob_get_level()) { ob_start(); }
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    $user = require_user();
    // Tolerancia de entrada: intentar JSON; si falla, usar POST/GET
    $raw = file_get_contents('php://input') ?: '';
    $tmp = $raw !== '' ? json_decode($raw, true) : null;
    $body = is_array($tmp) ? $tmp : (($_POST ?? []) ?: ($_GET ?? []));
    $provider = trim((string)($body['provider'] ?? 'auto')) ?: 'auto';
    $model    = trim((string)($body['model'] ?? ''));
    $knowledgeId = (int)($body['knowledge_id'] ?? 0);
    $fileId      = (int)($body['file_id'] ?? 0);
    $userIdHint  = isset($body['user_id']) ? (int)$body['user_id'] : null; // opcional: cuando el segundo campo es realmente el user_id

    $pdo = db();

    // Resolver archivo por file_id (prioritario)
    $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$fileId ?: $knowledgeId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kf && $knowledgeId > 0) {
        // fallback por knowledge_base.source_file → stored/original
        $st = $pdo->prepare('SELECT source_file FROM knowledge_base WHERE id = ? AND created_by = ?');
        $st->execute([$knowledgeId, $user['id']]);
        $kb = $st->fetch(PDO::FETCH_ASSOC);
        if ($kb && !empty($kb['source_file'])) {
            $st2 = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND (original_filename = ? OR stored_filename = ?) ORDER BY created_at DESC LIMIT 1');
            $st2->execute([$user['id'], $kb['source_file'], $kb['source_file']]);
            $kf = $st2->fetch(PDO::FETCH_ASSOC);
            // Si el usuario pasó user_id en lugar de file_id, permitir buscar con ese owner explicitamente
            if (!$kf && $userIdHint) {
                $st3 = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND (original_filename = ? OR stored_filename = ?) ORDER BY created_at DESC LIMIT 1');
                $st3->execute([$userIdHint, $kb['source_file'], $kb['source_file']]);
                $kf = $st3->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    if (!$kf) {
        json_error('Archivo no encontrado para vista previa', 404);
    }

    $filePath = __DIR__ . '/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];
    if (!is_file($filePath) || !is_readable($filePath)) {
        json_error('Archivo físico no accesible', 404);
    }

    $mime = (string)($kf['mime_type'] ?? 'application/octet-stream');
    $fileSizeMb = round(((int)($kf['file_size'] ?? 0)) / (1024*1024), 2);

    // Heurística simple de estrategia
    $isImage = preg_match('#^image/(png|jpe?g)$#i', $mime) === 1;
    $isPdf   = stripos($mime, 'pdf') !== false;
    $isText  = preg_match('#^(text/plain|text/markdown|text/csv)$#i', $mime) === 1;
    $isDoc   = preg_match('#application/(msword|vnd\.openxmlformats-officedocument\.wordprocessingml\.document)#i', $mime) === 1;

    $strategy = 'text_only';
    if ($isPdf) { $strategy = 'openai_input_file_prefer'; }
    if ($isImage || (!$isText && !$isPdf && !$isDoc)) { $strategy = 'vision_input_image'; }

    // Muestras (recortadas) solo para vista previa - evitar archivos grandes
    $sample = '';
    $sampleB64 = '';
    if ($fileSizeMb < 10) { // Solo procesar archivos menores a 10MB
        try {
            $sample = file_get_contents($filePath, false, null, 0, 200000) ?: '';
            $sampleB64 = $sample ? substr(base64_encode($sample), 0, 6000) : '';
        } catch (Throwable $e) {
            // Si falla la lectura, continuar sin muestra
            $sample = '';
            $sampleB64 = '';
        }
    }

    // Esquema sugerido (JSON Schema) para salida estructurada
    $jsonSchema = [
        'name' => 'knowledge_card',
        'strict' => true,
        'schema' => [
            'type' => 'object',
            'required' => ['resumen','puntos_clave'],
            'properties' => [
                'resumen' => [ 'type' => 'string', 'maxLength' => 1200 ],
                'puntos_clave' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'maxLength' => 240 ] ],
                'chunks_embed' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'id'=>['type'=>'string'], 'texto'=>['type'=>'string','maxLength'=>900] ] ] ]
            ]
        ]
    ];

    // Prompt propuesto (mismo formato que enviaríamos realmente)
    $prompt = "Eres un analista de trading. Si el archivo contiene texto legible, léelo y resume contenido textual real; no asumas 'escaneado' salvo evidencia.\n\n" .
              "Contexto del documento\n- Archivo: " . ($kf['original_filename'] ?? '') . "\n- Tipo: " . ($kf['file_type'] ?? '') . "\n- Tamaño: {$fileSizeMb} MB\n- Usuario: {$user['id']}\n\n" .
              "Objetivo: 8–12 bullets accionables (definiciones, reglas, patrones, confirmaciones, riesgos, recomendaciones, volumen/soportes y gestión de riesgo).\n" .
              "Salida estructurada según schema (resumen + puntos_clave + opcional chunks_embed).";

    $relative = 'api/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];

    $preview = [
        'provider' => $provider,
        'model' => $model,
        'strategy' => $strategy,
        'file' => [
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'mime_type' => $mime,
            'size_mb' => $fileSizeMb,
            'path' => $filePath,
            'relative_path' => $relative
        ],
        // Resumen de la “línea” de consulta que se armaría
        'request_summary' => (
            'provider=' . $provider .
            ' model=' . ($model ?: 'auto') .
            ' strategy=' . $strategy .
            ' file=' . $relative
        ),
        'request' => [
            'openai_responses' => [
                'endpoint' => 'POST /v1/responses',
                'response_format' => [ 'type' => 'json_schema', 'json_schema' => $jsonSchema ],
                'input' => [[
                    'role' => 'user',
                    'content' => $isPdf ? [
                        [ 'type' => 'input_text', 'text' => $prompt ],
                        [ 'type' => 'input_file', 'file_id' => 'file_XXXXXXXX' ]
                    ] : ($isImage ? [
                        [ 'type' => 'input_text', 'text' => $prompt ],
                        [ 'type' => 'input_image', 'image_url' => 'https://…/page1.jpg' ]
                    ] : [
                        [ 'type' => 'input_text', 'text' => $prompt . "\n\n[TEXTO_MUESTRA]\n" . substr($sample,0,2000) ]
                    ])
                ]],
                'note' => 'file_id real se resuelve y se cachea por usuario para no re-subir cada vez.'
            ],
            'gemini' => [
                'endpoint' => 'POST /v1beta/models/gemini-1.5-flash:generateContent',
                'parts' => [ 'inline_data base64' => $isPdf && ($kf['file_size'] < 6*1024*1024) ? true : false ]
            ]
        ],
        'samples' => [ 'text_snippet' => substr($sample,0,600), 'base64_len' => strlen($sampleB64) ]
    ];

    // Adjuntar posibles fugas de salida al resultado y loguearlas
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    if ($leak !== '') {
        $preview['debug_leak'] = substr($leak, 0, 8000);
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        @file_put_contents($logDir . '/ai_preview_debug.log', date('Y-m-d H:i:s') . " leak captured (user {$user['id']}):\n" . $preview['debug_leak'] . "\n\n", FILE_APPEND);
    }
    json_out([ 'ok' => true, 'context' => 'ai_summarize_request_preview_safe', 'preview' => $preview ]);

} catch (Throwable $e) {
    // Incluir cualquier fuga de salida también en el error para diagnóstico
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    $detail = ['message' => $e->getMessage()];
    if ($leak !== '') { $detail['leak'] = substr($leak, 0, 8000); }
    json_error('Error interno: ' . $e->getMessage(), 500, $detail);
}


