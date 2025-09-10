<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Método no permitido');
    }
    
    $diagnostic = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user['id'],
        'users_table_structure' => [],
        'recommended_fixes' => [],
        'sql_queries' => []
    ];
    
    // Verificar estructura de la tabla users
    try {
        $stmt = $pdo->query("DESCRIBE users");
        $diagnostic['users_table_structure'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar la columna id específicamente
        $id_column = null;
        foreach ($diagnostic['users_table_structure'] as $column) {
            if ($column['Field'] === 'id') {
                $id_column = $column;
                break;
            }
        }
        
        if ($id_column) {
            $diagnostic['id_column_info'] = $id_column;
            
            // Generar las consultas SQL corregidas basadas en la estructura real
            $id_type = $id_column['Type'];
            $id_nullable = $id_column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
            $id_default = $id_column['Default'] ? "DEFAULT {$id_column['Default']}" : '';
            $id_extra = $id_column['Extra'] ? $id_column['Extra'] : '';
            
            // Generar consultas SQL corregidas
            $diagnostic['sql_queries'] = [
                'ai_learning_metrics' => "
                    CREATE TABLE IF NOT EXISTS ai_learning_metrics (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id {$id_type} {$id_nullable},
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
                        user_id {$id_type} {$id_nullable},
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
                        user_id {$id_type} {$id_nullable},
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
                        user_id {$id_type} {$id_nullable},
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
                        user_id {$id_type} {$id_nullable},
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
            
            $diagnostic['recommended_fixes'][] = "Ajustar tipo de datos de user_id para coincidir con users.id: {$id_type}";
            $diagnostic['recommended_fixes'][] = "Verificar que users.id tenga índice PRIMARY KEY";
            $diagnostic['recommended_fixes'][] = "Usar las consultas SQL generadas automáticamente";
            
        } else {
            $diagnostic['recommended_fixes'][] = "ERROR: No se encontró columna 'id' en la tabla users";
        }
        
    } catch (Exception $e) {
        $diagnostic['recommended_fixes'][] = "Error verificando estructura de users: " . $e->getMessage();
    }
    
    // Verificar si las tablas ya existen
    $existing_tables = [];
    $expected_tables = ['ai_learning_metrics', 'ai_behavioral_patterns', 'ai_analysis_history', 'ai_learning_events', 'ai_behavior_profiles'];
    
    foreach ($expected_tables as $table_name) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
            $exists = $stmt->rowCount() > 0;
            $existing_tables[$table_name] = $exists;
        } catch (Exception $e) {
            $existing_tables[$table_name] = false;
        }
    }
    
    $diagnostic['existing_tables'] = $existing_tables;
    
    json_out($diagnostic);
    
} catch (Exception $e) {
    error_log("Error in migrate_fix_foreign_keys_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
