<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Router de resumen por IA que respeta el proveedor/modelo elegidos por el usuario.
// Estrategia: generar "muestra + metadatos" del archivo y pedir resumen a ai_analyze.php
// (que ya resuelve claves del usuario y rutea por proveedor).

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
    if ($knowledgeId <= 0) {
        json_error('knowledge_id requerido', 400);
    }

    $pdo = db();
    // Buscar por file_id explícito del usuario
    $kf = null;
    if ($fileId > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
        $stmt->execute([$fileId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Si no se envió file_id, intentar que knowledge_id sea knowledge_files.id
    if (!$kf && $knowledgeId > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$kf && $knowledgeId > 0) {
        // Intentar por knowledge_base -> source_file (nombre original)
        $stmt = $pdo->prepare('SELECT source_file FROM knowledge_base WHERE id = ? AND created_by = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kb = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($kb && !empty($kb['source_file'])) {
            $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND original_filename = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$user['id'], $kb['source_file']]);
            $kf = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$kf) {
                $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND stored_filename = ? ORDER BY created_at DESC LIMIT 1');
                $stmt->execute([$user['id'], $kb['source_file']]);
                $kf = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
    }

    if (!$kf) {
        json_error('Archivo no encontrado para este conocimiento', 404);
    }

    $filePath = __DIR__ . '/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];
    if (!is_file($filePath) || !is_readable($filePath)) {
        json_error('Archivo físico no accesible', 404);
    }

    // Preparar metadatos + muestra (sin depender de binarios grandes)
    $fileSize = (int)($kf['file_size'] ?? 0);
    $sampleBytes = 200000; // 200 KB
    $sample = file_get_contents($filePath, false, null, 0, $sampleBytes);
    $sampleB64 = $sample !== false ? substr(base64_encode($sample), 0, 6000) : '';

    $meta = [];
    $meta[] = 'INFORMACIÓN DEL ARCHIVO:';
    $meta[] = '- Nombre: ' . ($kf['original_filename'] ?? '');
    $meta[] = '- Tipo: ' . ($kf['file_type'] ?? '');
    $meta[] = '- Tamaño: ' . number_format($fileSize / (1024*1024), 2) . ' MB';
    $meta[] = '';
    $metaText = implode("\n", $meta);

    $prompt = "Eres un analista de trading. A partir de los metadatos y una muestra parcial (base64 recortada) del documento, \n" .
              "entrega un RESUMEN EN ESPAÑOL en 8-12 bullets claros orientados a TRADING: conceptos, definiciones, reglas \n" .
              "operativas, patrones, riesgos, y recomendaciones. Si detectas que el documento parece escaneado (sin texto real), \n" .
              "indícalo y enfoca el resumen a lo que se infiere por metadatos.\n\n" .
              $metaText . "\n[MUESTRA BASE64 RECORTADA]\n" . $sampleB64;

    // Llamar al router estándar ai_analyze.php (usa claves del usuario + proveedor)
    $apiUrl = getApiUrl('ai_analyze.php');
    $payload = [ 'provider' => $provider, 'model' => $model, 'prompt' => $prompt ];
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
        CURLOPT_TIMEOUT => 35,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http < 200 || $http >= 300) {
        json_error('Proveedor IA falló: HTTP ' . $http . ' ' . $err, 502);
    }

    $jr = json_decode($resp, true);
    $summary = $jr['text'] ?? null;
    if (!$summary) {
        json_error('Respuesta IA sin texto', 502);
    }

    $relative = 'api/uploads/knowledge/' . (int)$user['id'] . '/' . $kf['stored_filename'];

    json_out([
        'ok' => true,
        'summary' => $summary,
        'provider' => $provider,
        'model' => $model,
        'context' => 'ai_summarize_router_safe',
        'relative_path' => $relative,
        'request_summary' => 'provider=' . $provider . ' model=' . ($model ?: 'auto') . ' file=' . $relative,
        'used_file_id' => $fileId ?: null,
        'scope' => 'partial',
        'file_info' => [
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_size_mb' => round($fileSize / (1024*1024), 2),
            'mime_type' => $kf['mime_type'] ?? ''
        ]
    ]);
} catch (Throwable $e) {
    json_error('Error interno: ' . $e->getMessage(), 500);
}


