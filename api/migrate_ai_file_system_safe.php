<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Migración para crear las tablas del sistema de archivos de IA
 */
try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('method_not_allowed', 405, 'Only POST method allowed');
    }

    error_log("=== MIGRATE AI FILE SYSTEM ===");
    error_log("User ID: $user_id");

    $pdo = db();
    $results = [];

    // 1. Crear tabla knowledge_files
    $sql_knowledge_files = "
        CREATE TABLE IF NOT EXISTS knowledge_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            file_type VARCHAR(10) NOT NULL,
            file_size INT NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            upload_status ENUM('uploaded', 'processing', 'processed', 'error') DEFAULT 'uploaded',
            ai_file_id VARCHAR(255) NULL,
            vector_store_id VARCHAR(255) NULL,
            extraction_status ENUM('pending', 'extracting', 'extracted', 'failed') DEFAULT 'pending',
            error_message TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_status (upload_status),
            INDEX idx_extraction (extraction_status),
            INDEX idx_created (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $pdo->exec($sql_knowledge_files);
        $results['knowledge_files'] = 'created_successfully';
        error_log("Table knowledge_files created successfully");
    } catch (Exception $e) {
        $results['knowledge_files'] = 'error: ' . $e->getMessage();
        error_log("Error creating knowledge_files: " . $e->getMessage());
    }

    // 2. Crear tabla knowledge_base
    $sql_knowledge_base = "
        CREATE TABLE IF NOT EXISTS knowledge_base (
            id INT AUTO_INCREMENT PRIMARY KEY,
            knowledge_type ENUM('user_insight', 'market_data', 'analysis_pattern', 'strategy', 'document') DEFAULT 'user_insight',
            title VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            summary TEXT NULL,
            tags JSON NULL,
            confidence_score DECIMAL(3,2) DEFAULT 0.70,
            created_by INT NOT NULL,
            source_type ENUM('ai_extraction', 'manual', 'analysis', 'upload') DEFAULT 'ai_extraction',
            source_file VARCHAR(255) NULL,
            source_file_id INT NULL,
            ai_provider VARCHAR(50) NULL,
            extraction_method VARCHAR(100) NULL,
            vector_embedding JSON NULL,
            is_public BOOLEAN DEFAULT FALSE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_created (created_by, created_at),
            INDEX idx_type (knowledge_type),
            INDEX idx_active (is_active),
            INDEX idx_source_file (source_file_id),
            INDEX idx_confidence (confidence_score),
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (source_file_id) REFERENCES knowledge_files(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    try {
        $pdo->exec($sql_knowledge_base);
        $results['knowledge_base'] = 'created_successfully';
        error_log("Table knowledge_base created successfully");
    } catch (Exception $e) {
        $results['knowledge_base'] = 'error: ' . $e->getMessage();
        error_log("Error creating knowledge_base: " . $e->getMessage());
    }

    // 3. Crear directorio de uploads si no existe
    $upload_dir = __DIR__ . '/uploads/knowledge/' . $user_id;
    $upload_base = __DIR__ . '/uploads';
    $knowledge_dir = __DIR__ . '/uploads/knowledge';

    try {
        if (!is_dir($upload_base)) {
            if (!mkdir($upload_base, 0755, true)) {
                throw new Exception("Could not create uploads base directory");
            }
            error_log("Created uploads base directory");
        }

        if (!is_dir($knowledge_dir)) {
            if (!mkdir($knowledge_dir, 0755, true)) {
                throw new Exception("Could not create knowledge directory");
            }
            error_log("Created knowledge directory");
        }

        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Could not create user upload directory");
            }
            error_log("Created user upload directory: $upload_dir");
        }

        $results['directories'] = 'created_successfully';
    } catch (Exception $e) {
        $results['directories'] = 'error: ' . $e->getMessage();
        error_log("Error creating directories: " . $e->getMessage());
    }

    // 4. Crear archivo .htaccess para seguridad
    $htaccess_content = "Options -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\nphp_flag engine off\nHeader set X-Content-Type-Options nosniff\n";
    $htaccess_path = $upload_base . '/.htaccess';

    try {
        if (!file_exists($htaccess_path)) {
            if (!file_put_contents($htaccess_path, $htaccess_content)) {
                throw new Exception("Could not create .htaccess file");
            }
            error_log("Created .htaccess file for security");
        }
        $results['htaccess'] = 'created_successfully';
    } catch (Exception $e) {
        $results['htaccess'] = 'error: ' . $e->getMessage();
        error_log("Error creating .htaccess: " . $e->getMessage());
    }

    // 5. Verificar permisos
    $permissions_ok = is_dir($upload_dir) && is_writable($upload_dir);
    $results['permissions'] = $permissions_ok ? 'ok' : 'needs_fix';

    // 6. Verificar tablas creadas
    $tables_check = [];
    $tables = ['knowledge_files', 'knowledge_base'];
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch();
            $tables_check[$table] = $exists ? 'exists' : 'missing';
        } catch (Exception $e) {
            $tables_check[$table] = 'error: ' . $e->getMessage();
        }
    }

    $results['tables_check'] = $tables_check;

    // 7. Estado final
    $all_tables_ok = !in_array('missing', array_values($tables_check));
    $directories_ok = $results['directories'] === 'created_successfully';
    $permissions_ok = $results['permissions'] === 'ok';

    $overall_status = ($all_tables_ok && $directories_ok && $permissions_ok) ? 'success' : 'partial_success';

    error_log("Migration completed - Status: $overall_status");
    error_log("Tables OK: $all_tables_ok, Directories OK: $directories_ok, Permissions OK: $permissions_ok");

    json_out([
        'ok' => true,
        'status' => $overall_status,
        'results' => $results,
        'summary' => [
            'tables_created' => $all_tables_ok,
            'directories_created' => $directories_ok,
            'permissions_ok' => $permissions_ok,
            'ready_for_use' => $overall_status === 'success'
        ],
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    error_log("Error in migrate_ai_file_system_safe.php: " . $e->getMessage());
    json_error('internal_error', 500, 'Internal server error: ' . $e->getMessage());
}
