<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('Usuario no válido', 400);
    }

    $pdo = db();

    $limit = (int)($_GET['limit'] ?? 10);
    $offset = (int)($_GET['offset'] ?? 0);
    $symbol = $_GET['symbol'] ?? null;

    // Construir consulta
    $where_conditions = ['user_id = ?'];
    $params = [$user_id];

    if ($symbol) {
        $where_conditions[] = 'symbol = ?';
        $params[] = $symbol;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // Obtener historial de análisis
    $stmt = $pdo->prepare("
        SELECT 
            id,
            symbol,
            analysis_text,
            timeframe,
            outcome,
            traded,
            behavioral_context,
            ai_provider,
            analysis_type,
            confidence_score,
            created_at
        FROM ai_analysis_history 
        WHERE {$where_clause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $analyses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener total de registros
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM ai_analysis_history 
        WHERE {$where_clause}
    ");
    
    $stmt->execute(array_slice($params, 0, -2));
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Procesar análisis para incluir contexto comportamental parseado
    foreach ($analyses as &$analysis) {
        if ($analysis['behavioral_context']) {
            $analysis['behavioral_context'] = json_decode($analysis['behavioral_context'], true);
        }
    }

    json_out([
        'ok' => true,
        'analyses' => $analyses,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset
    ]);

} catch (Exception $e) {
    error_log("Error en ai_analysis_history_safe.php: " . $e->getMessage());
    json_error('Error obteniendo historial de análisis', 500);
}