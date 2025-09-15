<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Resumen seguro de archivo con IA (prioriza Gemini inline PDF). Fallback: híbrido + resumen LLM.

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    $user = require_user();
    $body = read_json_body();
    $knowledgeId = (int)($body['knowledge_id'] ?? 0);
    if ($knowledgeId <= 0) {
        json_error('knowledge_id requerido', 400);
    }

    $pdo = db();

    // Intentar encontrar en knowledge_files por id directo
    $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
    $stmt->execute([$knowledgeId, $user['id']]);
    $kf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$kf) {
        // Buscar vía knowledge_base → original_filename más reciente del usuario
        $stmt = $pdo->prepare('SELECT original_filename FROM knowledge_base WHERE id = ? AND created_by = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kb = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($kb && !empty($kb['original_filename'])) {
            $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND original_filename = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$user['id'], $kb['original_filename']]);
            $kf = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$kf) {
        json_error('Archivo no encontrado para este conocimiento', 404);
    }

    $filePath = __DIR__ . '/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];
    if (!is_file($filePath) || !is_readable($filePath)) {
        json_error('Archivo físico no accesible', 404);
    }

    $fileSize = (int)$kf['file_size'];
    $mime = $kf['mime_type'] ?: 'application/pdf';
    $maxBytes = 6 * 1024 * 1024; // 6MB para inline

    $geminiKey = getenv('GEMINI_API_KEY') ?: ($CONFIG['GEMINI_API_KEY'] ?? '');

    $summary = null;
    $method = null;
    $notes = [];

    // Intentar vía Gemini inline si hay clave y tamaño razonable
    if ($geminiKey && $fileSize > 0 && $fileSize <= $maxBytes) {
        $data = file_get_contents($filePath);
        if ($data !== false) {
            $b64 = base64_encode($data);
            $prompt = (
                "Eres un analista financiero. Resume en español y en 8-12 bullets claros el documento adjunto (PDF/DOC), " .
                "enfocado a trading: conceptos clave, definiciones importantes, reglas operativas, patrones, riesgos y recomendaciones prácticas. " .
                "Si el documento es imagen/escaneado y no puedes leer texto, indícalo explícitamente."
            );

            $payload = [
                'contents' => [[
                    'parts' => [
                        [ 'inline_data' => [ 'mime_type' => $mime, 'data' => $b64 ] ],
                        [ 'text' => $prompt ]
                    ]
                ]]
            ];

            $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($geminiKey));
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($resp !== false && $http >= 200 && $http < 300) {
                $jr = json_decode($resp, true);
                $text = $jr['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text) {
                    $summary = $text;
                    $method = 'gemini_pdf_inline';
                } else {
                    $notes[] = 'Gemini sin texto en respuesta';
                }
            } else {
                $notes[] = 'Gemini HTTP ' . $http . ' err=' . $err;
            }
        }
    } else {
        $notes[] = $geminiKey ? 'Archivo demasiado grande para inline' : 'GEMINI_API_KEY no configurada';
    }

    // Fallback: usar extracción híbrida local y pedir resumen a ai_analyze.php
    if (!$summary) {
        // Reusar extractor híbrido para texto parcial
        require_once __DIR__ . '/extract_content_hybrid_safe.php'; // no ejecuta directamente
        // Cargar algo de texto simple: para PDF sin texto visible, incluir metadatos
        $text = "METADATOS DEL PDF:\nINFORMACIÓN DEL ARCHIVO:\n- Nombre: " . ($kf['original_filename'] ?? '') . "\n- Tipo: " . ($kf['file_type'] ?? '') . "\n- Tamaño: " . number_format($fileSize / (1024*1024), 2) . " MB\n\n";

        // Intentar una lectura rápida de bytes para alimentar a la IA (limitada)
        $sample = file_get_contents($filePath, false, null, 0, 200000); // 200KB muestra
        if ($sample !== false) {
            $text .= "[MUESTRA BINARIA (BASE64 RECORTADA)]\n" . substr(base64_encode($sample), 0, 5000);
        }

        // Llamar a ai_analyze.php con prompt de resumen
        $prompt = "Resume profesionalmente el contenido adjunto (metadatos + muestra). Devuelve bullets claros para trading.\n\nCONTENIDO:\n" . $text;

        $apiUrl = getApiUrl('ai_analyze.php');
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
            ],
            CURLOPT_POSTFIELDS => json_encode(['provider' => 'auto', 'prompt' => $prompt]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $http >= 200 && $http < 300) {
            $jr = json_decode($resp, true);
            $summary = $jr['text'] ?? null;
            $method = 'llm_summary_fallback';
        } else {
            $notes[] = 'Fallback ai_analyze falló HTTP ' . $http;
        }
    }

    if (!$summary) {
        json_error('No se pudo generar resumen con IA', 502);
    }

    json_out([
        'ok' => true,
        'summary' => $summary,
        'method' => $method,
        'file_info' => [
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_size_mb' => round($fileSize / (1024*1024), 2),
            'mime_type' => $mime,
        ],
        'notes' => $notes,
    ]);
} catch (Throwable $e) {
    json_error('Error interno: ' . $e->getMessage(), 500);
}


