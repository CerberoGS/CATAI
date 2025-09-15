<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Diagnóstico no destructivo para resumir por IA: NO cambia datos, solo comprueba piezas.

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    $user = require_user();
    $body = read_json_body();
    $knowledgeId = (int)($body['knowledge_id'] ?? 0);
    $fileId      = (int)($body['file_id'] ?? 0);
    $provider = trim((string)($body['provider'] ?? 'auto')) ?: 'auto';
    $model    = trim((string)($body['model'] ?? ''));
    if ($knowledgeId <= 0) { json_error('knowledge_id requerido', 400); }

    $pdo = db();
    $kf = null;
    if ($fileId > 0) {
        // Camino directo por file_id del usuario actual
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
        $stmt->execute([$fileId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$kf && $knowledgeId > 0) {
        // Camino por knowledge_id (puede ser knowledge_files.id en algunos flujos)
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $ownerMismatch = false;

    if (!$kf && $knowledgeId > 0) {
        // En knowledge_base el nombre original está en la columna source_file
        $stmt = $pdo->prepare('SELECT source_file FROM knowledge_base WHERE id = ? AND created_by = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kb = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($kb && !empty($kb['source_file'])) {
            // Intento por original_filename con el usuario actual
            $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND original_filename = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$user['id'], $kb['source_file']]);
            $kf = $stmt->fetch(PDO::FETCH_ASSOC);
            // Intento adicional: stored_filename coincide exactamente con source_file (algunas cargas guardan este valor)
            if (!$kf) {
                $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND stored_filename = ? ORDER BY created_at DESC LIMIT 1');
                $stmt->execute([$user['id'], $kb['source_file']]);
                $kf = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    // Diagnóstico extra: si aún no hay match, buscar por id sin filtrar por usuario (solo diagnóstico, no para exponer datos)
    if (!$kf) {
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ?');
        $stmt->execute([$knowledgeId]);
        $any = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($any) { $kf = $any; $ownerMismatch = ($any['user_id'] ?? null) != ($user['id'] ?? null); }
    }

    $filePath = null;
    if ($kf) {
        $filePath = __DIR__ . '/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];
    }

    $exists = $filePath && is_file($filePath);
    $readable = $exists && is_readable($filePath);
    $size = $exists ? (int)filesize($filePath) : 0;
    $sampleBytes = 200000;
    $sampleRead = 0;
    $sampleB64Len = 0;
    if ($readable) {
        $sample = file_get_contents($filePath, false, null, 0, $sampleBytes);
        if ($sample !== false) {
            $sampleRead = strlen($sample);
            $sampleB64Len = strlen(base64_encode($sample));
        }
    }

    // Ping a ai_analyze.php (proveedor/model actuales) para validar ruta+claves
    $apiUrl = getApiUrl('ai_analyze.php');
    $payload = [ 'provider' => $provider, 'model' => $model, 'prompt' => 'Responde solo: PONG' ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
    ];
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $pong = null;
    if ($resp !== false && $http >= 200 && $http < 300) {
        $jr = json_decode($resp, true);
        $pong = $jr['text'] ?? null;
    }

    json_out([
        'ok' => true,
        'context' => 'ai_summarize_probe_safe',
        'probe' => [
            'provider' => $provider,
            'model' => $model,
            'used_file_id' => $fileId ?: null,
            'file' => [
                'found' => (bool)$kf,
                'owner_user_id' => $kf['user_id'] ?? null,
                'current_user_id' => $user['id'] ?? null,
                'owner_mismatch' => $ownerMismatch,
                'expected_path' => $filePath,
                'path_exists' => $exists,
                'readable' => $readable,
                'size_bytes' => $size,
                'sample_read_bytes' => $sampleRead,
                'sample_b64_len' => $sampleB64Len,
                'original_filename' => $kf['original_filename'] ?? null,
                'stored_filename' => $kf['stored_filename'] ?? null,
                'mime_type' => $kf['mime_type'] ?? null,
            ],
            'llm_ping' => [
                'http' => $http,
                'error' => $err ?: null,
                'text' => $pong,
            ],
        ],
    ]);
} catch (Throwable $e) {
    json_error('Error interno: ' . $e->getMessage(), 500);
}


