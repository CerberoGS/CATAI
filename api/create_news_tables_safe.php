<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    
    // Verificar que el usuario sea admin
    if (!($u['is_admin'] ?? false)) {
        json_out(['error' => 'unauthorized', 'message' => 'Solo administradores pueden crear tablas'], 403);
    }
    
    $pdo = db();
    
    // Crear tabla news_providers
    $createNewsProviders = "
    CREATE TABLE IF NOT EXISTS `news_providers` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `slug` varchar(64) NOT NULL,
        `name` varchar(100) NOT NULL,
        `status` enum('enabled','disabled') NOT NULL DEFAULT 'enabled',
        `base_url` varchar(255) DEFAULT NULL,
        `url_request` varchar(255) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_news_providers_slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($createNewsProviders);
    
    // Crear tabla user_news_api_keys
    $createUserNewsKeys = "
    CREATE TABLE IF NOT EXISTS `user_news_api_keys` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `user_id` bigint(20) unsigned NOT NULL,
        `provider_id` bigint(20) unsigned NOT NULL,
        `project_id` varchar(128) DEFAULT NULL,
        `label` varchar(100) DEFAULT NULL,
        `origin` enum('byok','managed') NOT NULL DEFAULT 'byok',
        `api_key_enc` text NOT NULL,
        `key_ciphertext` varbinary(4096) DEFAULT NULL,
        `key_fingerprint` char(64) DEFAULT NULL,
        `last4` char(4) DEFAULT NULL,
        `scopes` longtext DEFAULT NULL CHECK (json_valid(`scopes`)),
        `environment` enum('live','sandbox') NOT NULL DEFAULT 'live',
        `status` enum('active','disabled','rotating') NOT NULL DEFAULT 'active',
        `disabled_reason` text DEFAULT NULL,
        `error_count` int(11) NOT NULL DEFAULT 0,
        `last_used_at` datetime DEFAULT NULL,
        `expires_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT current_timestamp(),
        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_news_key_origin` (`user_id`,`provider_id`,`origin`),
        UNIQUE KEY `uq_news_key_label` (`user_id`,`provider_id`,`label`),
        KEY `idx_news_user` (`user_id`),
        KEY `idx_news_provider` (`provider_id`),
        KEY `idx_news_user_provider_env` (`user_id`,`provider_id`,`environment`),
        CONSTRAINT `fk_news_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_news_provider` FOREIGN KEY (`provider_id`) REFERENCES `news_providers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($createUserNewsKeys);
    
    // Insertar algunos proveedores de noticias de ejemplo
    $insertProviders = "
    INSERT IGNORE INTO `news_providers` (`slug`, `name`, `base_url`, `url_request`) VALUES
    ('newsapi', 'NewsAPI', 'https://newsapi.org/v2/', 'https://newsapi.org/v2/top-headlines?country=us&apiKey={API_KEY}'),
    ('financialmodelingprep', 'Financial Modeling Prep', 'https://financialmodelingprep.com/api/v3/', 'https://financialmodelingprep.com/api/v3/stock_news?limit=1&apikey={API_KEY}'),
    ('benzinga', 'Benzinga News', 'https://api.benzinga.com/api/v2/', 'https://api.benzinga.com/api/v2/news?token={API_KEY}'),
    ('marketwatch', 'MarketWatch', 'https://www.marketwatch.com/api/', 'https://www.marketwatch.com/api/news?apikey={API_KEY}'),
    ('bloomberg', 'Bloomberg API', 'https://api.bloomberg.com/', 'https://api.bloomberg.com/news?apikey={API_KEY}')
    ";
    
    $pdo->exec($insertProviders);
    
    json_out(['ok' => true, 'message' => 'Tablas de noticias creadas exitosamente']);
    
} catch (Throwable $e) {
    error_log("Error en create_news_tables_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
