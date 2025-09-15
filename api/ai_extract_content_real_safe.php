<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

try {
    if (!ob_get_level()) { ob_start(); }
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

    $user = require_user();
    
    // Obtener parámetros
    $knowledgeId = (int)($_GET['knowledge_id'] ?? $_POST['knowledge_id'] ?? 0);
    $fileId = (int)($_GET['file_id'] ?? $_POST['file_id'] ?? 0);
    $provider = trim($_GET['provider'] ?? $_POST['provider'] ?? 'auto');
    $model = trim($_GET['model'] ?? $_POST['model'] ?? '');
    
    if (!$knowledgeId && !$fileId) {
        json_error('Se requiere knowledge_id o file_id', 400);
    }
    
    $pdo = db();
    
    // Resolver archivo
    $kf = null;
    if ($fileId > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE id = ? AND user_id = ?');
        $stmt->execute([$fileId, $user['id']]);
        $kf = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$kf && $knowledgeId > 0) {
        $stmt = $pdo->prepare('SELECT source_file FROM knowledge_base WHERE id = ? AND created_by = ?');
        $stmt->execute([$knowledgeId, $user['id']]);
        $kb = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($kb && !empty($kb['source_file'])) {
            $stmt2 = $pdo->prepare('SELECT id, user_id, original_filename, stored_filename, file_type, file_size, mime_type FROM knowledge_files WHERE user_id = ? AND (original_filename = ? OR stored_filename = ?) ORDER BY created_at DESC LIMIT 1');
            $stmt2->execute([$user['id'], $kb['source_file'], $kb['source_file']]);
            $kf = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    if (!$kf) {
        json_error('Archivo no encontrado', 404);
    }
    
    $filePath = __DIR__ . '/uploads/knowledge/' . $user['id'] . '/' . $kf['stored_filename'];
    if (!is_file($filePath) || !is_readable($filePath)) {
        json_error('Archivo físico no accesible', 404);
    }
    
    // Obtener API key del usuario
    $apiKey = get_api_key_for($user['id'], $provider);
    if (empty($apiKey)) {
        json_error("No se encontró API key para el proveedor: $provider", 400);
    }
    
    // Construir prompt para trading
    $prompt = "Eres un analista de trading experto. Analiza el siguiente documento y extrae información valiosa para trading:\n\n" .
              "ARCHIVO: " . $kf['original_filename'] . "\n" .
              "TIPO: " . $kf['file_type'] . "\n" .
              "TAMAÑO: " . round($kf['file_size'] / (1024*1024), 2) . " MB\n\n" .
              "Proporciona un resumen estructurado en español con:\n" .
              "1. RESUMEN EJECUTIVO (2-3 líneas)\n" .
              "2. CONCEPTOS CLAVE (5-8 puntos)\n" .
              "3. ESTRATEGIAS DE TRADING (3-5 puntos)\n" .
              "4. GESTIÓN DE RIESGO (2-3 puntos)\n" .
              "5. RECOMENDACIONES (2-3 puntos)\n\n" .
              "Enfócate en información práctica y accionable para traders.";
    
    // Para archivos pequeños, incluir contenido directo
    $fileSizeMb = round($kf['file_size'] / (1024*1024), 2);
    if ($fileSizeMb < 5) {
        $content = file_get_contents($filePath, false, null, 0, 1000000); // 1MB máximo
        if ($content) {
            $prompt .= "\n\nCONTENIDO DEL ARCHIVO:\n" . substr($content, 0, 50000); // 50KB máximo
        }
    }
    
    // Llamar a la IA
    $aiResponse = http_post_json('https://api.openai.com/v1/chat/completions', [
        'model' => $model ?: 'gpt-4o-mini',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 2000,
        'temperature' => 0.3
    ], [
        'Authorization' => 'Bearer ' . $apiKey
    ], 30);
    
    if (!isset($aiResponse['choices'][0]['message']['content'])) {
        json_error('Error en respuesta de IA: ' . json_encode($aiResponse), 500);
    }
    
    $summary = $aiResponse['choices'][0]['message']['content'];
    
    // Actualizar knowledge_base con el resumen (sin extraction_status que no existe en esta tabla)
    $stmt = $pdo->prepare('UPDATE knowledge_base SET content = ?, summary = ?, updated_at = NOW() WHERE id = ? AND created_by = ?');
    $stmt->execute([$summary, substr($summary, 0, 500), $knowledgeId, $user['id']]);
    
    // Actualizar knowledge_files con extraction_status
    $stmt = $pdo->prepare('UPDATE knowledge_files SET extraction_status = "completed", extracted_items = 1, updated_at = NOW() WHERE id = ? AND user_id = ?');
    $stmt->execute([$kf['id'], $user['id']]);
    
    // Verificar fugas de salida
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    
    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'file' => [
            'original_filename' => $kf['original_filename'],
            'stored_filename' => $kf['stored_filename'],
            'file_type' => $kf['file_type'],
            'size_mb' => $fileSizeMb
        ],
        'ai' => [
            'provider' => $provider,
            'model' => $model ?: 'gpt-4o-mini',
            'summary' => $summary
        ],
        'knowledge_updated' => true,
        'leak' => $leak
    ]);
    
} catch (Throwable $e) {
    $leak = '';
    if (ob_get_level()) { $leak = ob_get_clean(); }
    
    json_error('Error: ' . $e->getMessage(), 500, [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'leak' => $leak
    ]);
}
