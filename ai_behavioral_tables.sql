-- Tablas para el sistema de IA comportamental
-- Ejecutar este script en la base de datos para crear las tablas necesarias

-- Tabla de métricas de aprendizaje
CREATE TABLE IF NOT EXISTS ai_learning_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_analyses INT DEFAULT 0,
    success_rate DECIMAL(5,2) DEFAULT 0.00,
    patterns_learned INT DEFAULT 0,
    accuracy_score DECIMAL(5,2) DEFAULT 0.00,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_metrics (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de patrones comportamentales
CREATE TABLE IF NOT EXISTS ai_behavioral_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de historial de análisis de IA
CREATE TABLE IF NOT EXISTS ai_analysis_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de eventos de aprendizaje
CREATE TABLE IF NOT EXISTS ai_learning_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_data JSON,
    confidence_impact DECIMAL(3,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_type (user_id, event_type),
    INDEX idx_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de perfiles de comportamiento de IA
CREATE TABLE IF NOT EXISTS ai_behavior_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar datos iniciales para usuarios existentes
INSERT IGNORE INTO ai_learning_metrics (user_id, total_analyses, success_rate, patterns_learned, accuracy_score)
SELECT id, 0, 0, 0, 0 FROM users;

INSERT IGNORE INTO ai_behavior_profiles (user_id, trading_style, risk_tolerance, time_preference, preferred_indicators, analysis_depth)
SELECT 
    id, 
    'balanced', 
    'moderate', 
    'intraday', 
    JSON_ARRAY('rsi14', 'ema20', 'sma20'),
    'advanced'
FROM users;

-- Crear índices adicionales para optimización
CREATE INDEX idx_learning_metrics_success ON ai_learning_metrics(user_id, success_rate DESC);
CREATE INDEX idx_behavioral_patterns_frequency ON ai_behavioral_patterns(user_id, frequency DESC);
CREATE INDEX idx_analysis_history_outcome ON ai_analysis_history(user_id, success_outcome, created_at DESC);
CREATE INDEX idx_learning_events_impact ON ai_learning_events(user_id, confidence_impact DESC, created_at DESC);
