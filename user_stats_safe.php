<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Solo GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Método no permitido', 405);
}

// Requerir autenticación
try {
    $user = require_user();
} catch (Exception $e) {
    json_error('Error de autenticación: ' . $e->getMessage(), 401);
}

// Obtener parámetros de fecha
$from_date = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to_date = $_GET['to'] ?? date('Y-m-d');

// Validar fechas
if (!strtotime($from_date) || !strtotime($to_date)) {
    json_error('Fechas inválidas');
}

try {
    // Estadísticas de análisis
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_analyses,
            COUNT(CASE WHEN created_at >= ? THEN 1 END) as analyses_this_week,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as analyses_today
        FROM analysis 
        WHERE user_id = ? AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([date('Y-m-d', strtotime('-7 days')), $user['id'], $from_date, $to_date . ' 23:59:59']);
    $analysis_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Estadísticas de consultas de datos (simulado - basado en análisis)
    $data_queries_today = $analysis_stats['analyses_today'] ?? 0;
    $data_queries_week = $analysis_stats['analyses_this_week'] ?? 0;

    // Símbolos únicos analizados
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT symbol) as unique_symbols
        FROM analysis 
        WHERE user_id = ? AND created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$user['id'], $from_date, $to_date . ' 23:59:59']);
    $symbol_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Uso de IA (simulado - basado en análisis que tienen AI)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as ai_usage
        FROM analysis 
        WHERE user_id = ? AND created_at BETWEEN ? AND ? AND analysis_text LIKE '%IA%'
    ");
    $stmt->execute([$user['id'], $from_date, $to_date . ' 23:59:59']);
    $ai_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Actividad reciente
    $stmt = $pdo->prepare("
        SELECT symbol, title, created_at, outcome, traded
        FROM analysis 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_out([
        'ok' => true,
        'stats' => [
            'total_analyses' => (int)($analysis_stats['total_analyses'] ?? 0),
            'analyses_this_week' => (int)($analysis_stats['analyses_this_week'] ?? 0),
            'analyses_today' => (int)($analysis_stats['analyses_today'] ?? 0),
            'data_queries_today' => $data_queries_today,
            'data_queries_week' => $data_queries_week,
            'unique_symbols' => (int)($symbol_stats['unique_symbols'] ?? 0),
            'ai_usage_today' => (int)($ai_stats['ai_usage'] ?? 0),
            'period' => [
                'from' => $from_date,
                'to' => $to_date
            ]
        ],
        'recent_activity' => $recent_activity
    ]);

} catch (Exception $e) {
    json_error('Error de base de datos: ' . $e->getMessage());
}
?>