<?php
declare(strict_types=1);

require_once 'common.php';

try {
    $user = require_user();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $table_name = $input['table_name'] ?? '';
    
    if (empty($table_name)) {
        json_error('Nombre de tabla requerido');
    }
    
    // Primero obtener la estructura correcta de la tabla users
    $stmt = $pdo->query("DESCRIBE users");
    $users_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $id_column = null;
    foreach ($users_structure as $column) {
        if ($column['Field'] === 'id') {
            $id_column = $column;
            break;
        }
    }
    
    if (!$id_column) {
        json_error('No se encontró columna id en la tabla users');
    }
    
    $id_type = $id_column['Type'];
    $id_nullable = $id_column['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
    
    // Definir las consultas SQL corregidas
    $table_queries = [
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
    
    if (!isset($table_queries[$table_name])) {
        json_error('Tabla no válida: ' . $table_name);
    }
    
    // Ejecutar la consulta de creación
    try {
        $pdo->exec($table_queries[$table_name]);
        
        // Verificar que la tabla se creó correctamente
        $stmt = $pdo->query("SHOW TABLES LIKE '$table_name'");
        $exists = $stmt->rowCount() > 0;
        
        if ($exists) {
            // Obtener información de la tabla
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table_name");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            json_out([
                'ok' => true,
                'message' => "Tabla $table_name creada exitosamente con estructura corregida",
                'table_name' => $table_name,
                'exists' => true,
                'rows' => intval($count),
                'user_id_type' => $id_type,
                'sql_used' => $table_queries[$table_name]
            ]);
        } else {
            json_error("Error creando tabla $table_name");
        }
        
    } catch (Exception $e) {
        json_error("Error ejecutando SQL para $table_name: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Error in migrate_execute_fixed_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
