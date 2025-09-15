<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Limpiar cualquier output previo
while (ob_get_level() > 0) {
    ob_end_clean();
}

ob_start();

try {
    // Incluir archivos necesarios
    $config = require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/helpers.php';
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/jwt.php';
    
    // Verificar autenticación
    $user = require_user();
    $userId = $user['id'];
    
    // Verificar que el usuario existe y está activo
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        json_error('Usuario no encontrado o inactivo', 404);
        exit;
    }
    
    $results = [
        'metrics_created' => 0,
        'patterns_created' => 0,
        'profiles_created' => 0,
        'history_created' => 0,
        'knowledge_created' => 0
    ];
    
    // 1. Crear métricas de aprendizaje iniciales
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ai_learning_metrics 
            (user_id, total_analyses, successful_analyses, failed_analyses, accuracy_score, 
             learning_rate, confidence_level, last_analysis_date, created_at, updated_at)
            VALUES (?, 0, 0, 0, 0.0, 0.1, 0.5, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $stmt->execute([$userId]);
        $results['metrics_created'] = $stmt->rowCount();
    } catch (PDOException $e) {
        // Si la tabla no existe, continuar
        error_log("ai_learning_metrics table not found: " . $e->getMessage());
    }
    
    // 2. Crear perfil comportamental inicial
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ai_behavior_profiles 
            (user_id, trading_style, risk_tolerance, time_preference, analysis_preference, 
             learning_style, confidence_level, created_at, updated_at)
            VALUES (?, 'balanced', 'moderate', 'medium', 'comprehensive', 'adaptive', 0.5, NOW(), NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $stmt->execute([$userId]);
        $results['profiles_created'] = $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("ai_behavior_profiles table not found: " . $e->getMessage());
    }
    
    // 3. Crear patrones comportamentales iniciales
    try {
        $patterns = [
            ['pattern_type' => 'entry_timing', 'pattern_name' => 'Entrada Temprana', 'confidence' => 0.3, 'frequency' => 1],
            ['pattern_type' => 'risk_management', 'pattern_name' => 'Gestión Conservadora', 'confidence' => 0.4, 'frequency' => 1],
            ['pattern_type' => 'exit_strategy', 'pattern_name' => 'Salida por Objetivo', 'confidence' => 0.35, 'frequency' => 1]
        ];
        
        foreach ($patterns as $pattern) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_behavioral_patterns 
                (user_id, pattern_type, pattern_name, confidence_score, frequency, 
                 success_rate, last_seen, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 0.0, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                confidence_score = VALUES(confidence_score),
                frequency = frequency + 1,
                updated_at = NOW()
            ");
            $stmt->execute([
                $userId, 
                $pattern['pattern_type'], 
                $pattern['pattern_name'], 
                $pattern['confidence'], 
                $pattern['frequency']
            ]);
            $results['patterns_created'] += $stmt->rowCount();
        }
    } catch (PDOException $e) {
        error_log("ai_behavioral_patterns table not found: " . $e->getMessage());
    }
    
    // 4. Crear eventos de aprendizaje iniciales
    try {
        $events = [
            ['event_type' => 'system_init', 'description' => 'Sistema de IA inicializado', 'impact_score' => 0.1],
            ['event_type' => 'first_analysis', 'description' => 'Primer análisis realizado', 'impact_score' => 0.2],
            ['event_type' => 'pattern_detected', 'description' => 'Patrón comportamental detectado', 'impact_score' => 0.15]
        ];
        
        foreach ($events as $event) {
            $stmt = $pdo->prepare("
                INSERT INTO ai_learning_events 
                (user_id, event_type, description, impact_score, metadata, created_at)
                VALUES (?, ?, ?, ?, '{}', NOW())
            ");
            $stmt->execute([
                $userId, 
                $event['event_type'], 
                $event['description'], 
                $event['impact_score']
            ]);
            $results['history_created'] += $stmt->rowCount();
        }
    } catch (PDOException $e) {
        error_log("ai_learning_events table not found: " . $e->getMessage());
    }
    
    // 5. Crear conocimiento base inicial
    try {
        $knowledge = [
            [
                'knowledge_type' => 'market_pattern',
                'title' => 'Patrón de Doble Techo',
                'content' => 'El patrón de doble techo es una formación de reversión bajista que se forma después de una tendencia alcista.',
                'summary' => 'Formación de reversión bajista con dos máximos iguales',
                'tags' => '["patrón", "reversión", "técnico", "doble_techo"]',
                'confidence_score' => 0.85
            ],
            [
                'knowledge_type' => 'indicator_rule',
                'title' => 'RSI Sobrecompra/Sobreventa',
                'content' => 'El RSI indica sobrecompra cuando está por encima de 70 y sobreventa cuando está por debajo de 30.',
                'summary' => 'RSI >70 sobrecompra, <30 sobreventa',
                'tags' => '["rsi", "indicador", "sobrecompra", "sobreventa"]',
                'confidence_score' => 0.90
            ],
            [
                'knowledge_type' => 'strategy',
                'title' => 'Estrategia de Media Móvil Cruzada',
                'content' => 'Compra cuando la media móvil rápida cruza por encima de la lenta y vende cuando cruza por debajo.',
                'summary' => 'Cruce de medias móviles para señales de compra/venta',
                'tags' => '["media_móvil", "cruce", "tendencia", "señales"]',
                'confidence_score' => 0.75
            ]
        ];
        
        foreach ($knowledge as $item) {
            $stmt = $pdo->prepare("
                INSERT INTO knowledge_base 
                (knowledge_type, title, content, summary, tags, confidence_score, 
                 usage_count, success_rate, created_by, source_type, is_public, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0.0, ?, 'manual', 1, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $stmt->execute([
                $item['knowledge_type'],
                $item['title'],
                $item['content'],
                $item['summary'],
                $item['tags'],
                $item['confidence_score'],
                $userId
            ]);
            $results['knowledge_created'] += $stmt->rowCount();
        }
    } catch (PDOException $e) {
        error_log("knowledge_base table not found: " . $e->getMessage());
    }
    
    // Limpiar buffer y enviar respuesta
    ob_end_clean();
    
    json_out([
        'ok' => true,
        'message' => 'Datos de IA inicializados exitosamente',
        'user_id' => $userId,
        'results' => $results,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Throwable $e) {
    // Limpiar buffer en caso de error
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    error_log("Error en init_ai_data_safe.php: " . $e->getMessage());
    
    json_error('Error inicializando datos de IA: ' . $e->getMessage(), 500);
}
?>