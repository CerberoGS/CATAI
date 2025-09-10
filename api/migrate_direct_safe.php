<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? 'create_tables';
    
    $results = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user['id'],
        'action' => $action,
        'results' => []
    ];
    
    // Basado en la estructura real de la tabla users que vimos
    // id es BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT
    $user_id_type = 'BIGINT(20) UNSIGNED NOT NULL';
    
    if ($action === 'create_tables') {
        // Definir las consultas SQL con el tipo correcto
        $table_queries = [
            'ai_learning_metrics' => "
                CREATE TABLE IF NOT EXISTS ai_learning_metrics (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id {$user_id_type},
                    total_analyses INT DEFAULT 0,
                    success_rate DECIMAL(5,2) DEFAULT 0.00,
                    patterns_learned INT DEFAULT 0,
                    accuracy_score DECIMAL(5,2) DEFAULT 0.00,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_metrics (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'ai_behavioral_patterns' => "
                CREATE TABLE IF NOT EXISTS ai_behavioral_patterns (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id {$user_id_type},
                    name VARCHAR(255) NOT NULL,
                    description TEXT,
                    pattern_type VARCHAR(100) DEFAULT 'general',
                    confidence DECIMAL(3,2) DEFAULT 0.50,
                    frequency INT DEFAULT 1,
                    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_pattern (user_id, name),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_confidence (user_id, confidence DESC),
                    INDEX idx_pattern_type (pattern_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'ai_analysis_history' => "
                CREATE TABLE IF NOT EXISTS ai_analysis_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id {$user_id_type},
                    symbol VARCHAR(20) NOT NULL,
                    analysis_type VARCHAR(50) DEFAULT 'comprehensive',
                    timeframe VARCHAR(20) DEFAULT '15min',
                    content LONGTEXT,
                    ai_provider VARCHAR(50) DEFAULT 'behavioral_ai',
                    confidence_score DECIMAL(3,2) DEFAULT 0.50,
                    success_outcome BOOLEAN NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_symbol (user_id, symbol),
                    INDEX idx_user_created (user_id, created_at DESC),
                    INDEX idx_symbol_created (symbol, created_at DESC),
                    INDEX idx_analysis_type (analysis_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'ai_learning_events' => "
                CREATE TABLE IF NOT EXISTS ai_learning_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id {$user_id_type},
                    event_type VARCHAR(50) NOT NULL,
                    event_data JSON,
                    confidence_impact DECIMAL(3,2) DEFAULT 0.00,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_type (user_id, event_type),
                    INDEX idx_created (created_at DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            
            'ai_behavior_profiles' => "
                CREATE TABLE IF NOT EXISTS ai_behavior_profiles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id {$user_id_type},
                    trading_style ENUM('conservative', 'balanced', 'aggressive', 'expert') DEFAULT 'balanced',
                    risk_tolerance ENUM('low', 'moderate', 'high', 'extreme') DEFAULT 'moderate',
                    time_preference ENUM('scalping', 'intraday', 'swing', 'position') DEFAULT 'intraday',
                    preferred_indicators JSON,
                    analysis_depth ENUM('basic', 'advanced', 'expert') DEFAULT 'advanced',
                    learning_enabled BOOLEAN DEFAULT TRUE,
                    adaptive_prompts BOOLEAN DEFAULT TRUE,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_profile (user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            "
        ];
        
        // Crear cada tabla
        foreach ($table_queries as $table_name => $sql) {
            try {
                $pdo->exec($sql);
                
                // Verificar que se creó correctamente
                $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
                $exists = $stmt->rowCount() > 0;
                
                if ($exists) {
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table_name");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    $results['results'][] = [
                        'table' => $table_name,
                        'status' => 'success',
                        'message' => "Tabla creada exitosamente",
                        'rows' => intval($count)
                    ];
                } else {
                    $results['results'][] = [
                        'table' => $table_name,
                        'status' => 'error',
                        'message' => "Error: Tabla no se creó"
                    ];
                }
                
            } catch (Exception $e) {
                $results['results'][] = [
                    'table' => $table_name,
                    'status' => 'error',
                    'message' => "Error: " . $e->getMessage()
                ];
            }
        }
        
    } elseif ($action === 'insert_initial_data') {
        // Insertar datos iniciales para usuarios existentes
        try {
            $stmt = $pdo->query("SELECT id FROM users");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $users_processed = 0;
            $errors = [];
            
            foreach ($users as $user_data) {
                $user_id = $user_data['id'];
                
                try {
                    // Insertar métricas de aprendizaje por defecto
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO ai_learning_metrics 
                        (user_id, total_analyses, success_rate, patterns_learned, accuracy_score)
                        VALUES (?, 0, 0, 0, 0)
                    ");
                    $stmt->execute([$user_id]);
                    
                    // Insertar perfil de comportamiento por defecto
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO ai_behavior_profiles 
                        (user_id, trading_style, risk_tolerance, time_preference, preferred_indicators, analysis_depth)
                        VALUES (?, 'balanced', 'moderate', 'intraday', ?, 'advanced')
                    ");
                    $default_indicators = json_encode(['rsi14', 'ema20', 'sma20']);
                    $stmt->execute([$user_id, $default_indicators]);
                    
                    $users_processed++;
                    
                } catch (Exception $e) {
                    $errors[] = "Usuario $user_id: " . $e->getMessage();
                }
            }
            
            $results['results'][] = [
                'action' => 'insert_initial_data',
                'status' => 'success',
                'message' => "Datos iniciales procesados",
                'users_processed' => $users_processed,
                'total_users' => count($users),
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $results['results'][] = [
                'action' => 'insert_initial_data',
                'status' => 'error',
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }
    
    json_out($results);
    
} catch (Exception $e) {
    error_log("Error in migrate_direct_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
