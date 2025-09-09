-- Script SQL para nuevas funcionalidades
-- Ejecutar en la base de datos MySQL

-- Tabla para favoritos de usuarios
CREATE TABLE IF NOT EXISTS user_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    symbol VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_symbol (user_id, symbol),
    INDEX idx_user_id (user_id),
    INDEX idx_symbol (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para notificaciones de usuarios
CREATE TABLE IF NOT EXISTS user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data JSON NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para configuración de temas de usuarios
CREATE TABLE IF NOT EXISTS user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    theme ENUM('light', 'dark', 'auto') DEFAULT 'light',
    language VARCHAR(10) DEFAULT 'es',
    timezone VARCHAR(50) DEFAULT 'America/Chicago',
    notifications_enabled BOOLEAN DEFAULT TRUE,
    email_notifications BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para historial de análisis rápidos
CREATE TABLE IF NOT EXISTS analysis_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    symbol VARCHAR(20) NOT NULL,
    analysis_type VARCHAR(50) NOT NULL,
    parameters JSON NOT NULL,
    result_summary TEXT,
    execution_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_symbol (symbol),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para métricas de rendimiento
CREATE TABLE IF NOT EXISTS performance_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL para métricas globales
    endpoint VARCHAR(100) NOT NULL,
    method VARCHAR(10) NOT NULL,
    response_time_ms INT NOT NULL,
    status_code INT NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_endpoint (endpoint),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar datos de ejemplo para testing
INSERT IGNORE INTO user_preferences (user_id, theme, language, timezone) 
SELECT id, 'light', 'es', 'America/Chicago' 
FROM users 
WHERE id NOT IN (SELECT user_id FROM user_preferences);

-- Crear índices adicionales para optimización
CREATE INDEX idx_analysis_user_symbol ON analysis (user_id, symbol);
CREATE INDEX idx_analysis_created_at ON analysis (created_at);
CREATE INDEX idx_usage_log_user_endpoint ON usage_log (user_id, endpoint);
CREATE INDEX idx_usage_log_created_at ON usage_log (created_at);

-- Comentarios para documentación
ALTER TABLE user_favorites COMMENT = 'Símbolos favoritos por usuario';
ALTER TABLE user_notifications COMMENT = 'Sistema de notificaciones para usuarios';
ALTER TABLE user_preferences COMMENT = 'Preferencias de usuario (tema, idioma, etc.)';
ALTER TABLE analysis_history COMMENT = 'Historial de análisis rápidos';
ALTER TABLE performance_metrics COMMENT = 'Métricas de rendimiento de la aplicación';
