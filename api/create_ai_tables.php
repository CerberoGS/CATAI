<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no válido', 400);
}

try {
    $pdo = db();
    
    $results = [
        'ok' => true,
        'user_id' => $user_id,
        'tables_created' => [],
        'errors' => []
    ];

    // Crear tabla ai_behavioral_patterns
    $sql_patterns = "
        CREATE TABLE IF NOT EXISTS `ai_behavioral_patterns` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `pattern_type` varchar(50) NOT NULL,
            `pattern_data` text NOT NULL,
            `frequency` int(11) DEFAULT 1,
            `confidence` decimal(5,2) DEFAULT 0.00,
            `last_seen` timestamp NULL DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `pattern_type` (`pattern_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($sql_patterns);
        $results['tables_created'][] = 'ai_behavioral_patterns';
    } catch (Exception $e) {
        $results['errors'][] = 'ai_behavioral_patterns: ' . $e->getMessage();
    }

    // Crear tabla ai_behavior_profiles
    $sql_profiles = "
        CREATE TABLE IF NOT EXISTS `ai_behavior_profiles` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `trading_style` varchar(50) DEFAULT 'equilibrado',
            `risk_tolerance` varchar(50) DEFAULT 'moderada',
            `time_preference` varchar(50) DEFAULT 'intradia',
            `preferred_symbols` text DEFAULT '[]',
            `analysis_frequency` int(11) DEFAULT 0,
            `success_patterns` text DEFAULT '{}',
            `failure_patterns` text DEFAULT '{}',
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($sql_profiles);
        $results['tables_created'][] = 'ai_behavior_profiles';
    } catch (Exception $e) {
        $results['errors'][] = 'ai_behavior_profiles: ' . $e->getMessage();
    }

    // Crear tabla ai_learning_metrics
    $sql_metrics = "
        CREATE TABLE IF NOT EXISTS `ai_learning_metrics` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `total_analyses` int(11) DEFAULT 0,
            `successful_analyses` int(11) DEFAULT 0,
            `failed_analyses` int(11) DEFAULT 0,
            `accuracy_rate` decimal(5,2) DEFAULT 0.00,
            `learning_score` decimal(5,2) DEFAULT 0.00,
            `last_analysis` timestamp NULL DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($sql_metrics);
        $results['tables_created'][] = 'ai_learning_metrics';
    } catch (Exception $e) {
        $results['errors'][] = 'ai_learning_metrics: ' . $e->getMessage();
    }

    // Crear tabla ai_learning_events
    $sql_events = "
        CREATE TABLE IF NOT EXISTS `ai_learning_events` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `event_type` varchar(50) NOT NULL,
            `event_data` text NOT NULL,
            `symbol` varchar(20) DEFAULT NULL,
            `outcome` varchar(20) DEFAULT NULL,
            `confidence` decimal(5,2) DEFAULT 0.00,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `event_type` (`event_type`),
            KEY `symbol` (`symbol`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($sql_events);
        $results['tables_created'][] = 'ai_learning_events';
    } catch (Exception $e) {
        $results['errors'][] = 'ai_learning_events: ' . $e->getMessage();
    }

    // Crear tabla ai_analysis_history
    $sql_history = "
        CREATE TABLE IF NOT EXISTS `ai_analysis_history` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `symbol` varchar(20) NOT NULL,
            `analysis_type` varchar(50) NOT NULL,
            `prompt` text NOT NULL,
            `response` text NOT NULL,
            `confidence` decimal(5,2) DEFAULT 0.00,
            `outcome` varchar(20) DEFAULT NULL,
            `traded` tinyint(1) DEFAULT 0,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id` (`user_id`),
            KEY `symbol` (`symbol`),
            KEY `analysis_type` (`analysis_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    try {
        $pdo->exec($sql_history);
        $results['tables_created'][] = 'ai_analysis_history';
    } catch (Exception $e) {
        $results['errors'][] = 'ai_analysis_history: ' . $e->getMessage();
    }

    json_out($results);

} catch (Exception $e) {
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
