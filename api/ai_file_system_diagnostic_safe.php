<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

/**
 * Diagnóstico completo del sistema de archivos de IA
 */
try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('method_not_allowed', 405, 'Only GET method allowed');
    }

    error_log("=== AI FILE SYSTEM DIAGNOSTIC ===");
    error_log("User ID: $user_id");

    $pdo = db();
    $diagnostic = [
        'user_info' => [
            'id' => $user_id,
            'email' => $user['email'] ?? 'N/A'
        ],
        'system_status' => [],
        'database_status' => [],
        'file_system_status' => [],
        'ai_providers_status' => [],
        'recommendations' => []
    ];

    // 1. Verificar tablas de base de datos
    try {
        $tables = ['knowledge_files', 'knowledge_base'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch();
            
            $diagnostic['database_status'][$table] = [
                'exists' => (bool)$exists,
                'status' => $exists ? 'ok' : 'missing'
            ];
            
            if ($exists) {
                // Contar registros
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table WHERE user_id = $user_id");
                $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                $diagnostic['database_status'][$table]['user_records'] = (int)$count;
            }
        }
    } catch (Exception $e) {
        $diagnostic['database_status']['error'] = $e->getMessage();
    }

    // 2. Verificar directorio de uploads
    $upload_dir = __DIR__ . '/uploads/knowledge/' . $user_id;
    $diagnostic['file_system_status'] = [
        'upload_directory' => $upload_dir,
        'exists' => is_dir($upload_dir),
        'writable' => is_dir($upload_dir) ? is_writable($upload_dir) : false,
        'files_count' => 0,
        'total_size_mb' => 0
    ];

    if (is_dir($upload_dir)) {
        $files = glob($upload_dir . '/*');
        $diagnostic['file_system_status']['files_count'] = count($files);
        
        $total_size = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                $total_size += filesize($file);
            }
        }
        $diagnostic['file_system_status']['total_size_mb'] = round($total_size / 1024 / 1024, 2);
    }

    // 3. Verificar proveedores de IA
    $ai_providers = ['openai', 'gemini', 'claude', 'xai', 'deepseek'];
    foreach ($ai_providers as $provider) {
        $key = get_api_key_for($user_id, $provider, strtoupper($provider) . '_API_KEY');
        $diagnostic['ai_providers_status'][$provider] = [
            'configured' => !empty($key),
            'key_length' => !empty($key) ? strlen($key) : 0,
            'key_preview' => !empty($key) ? substr($key, 0, 8) . '...' : null
        ];
    }

    // 4. Verificar configuración del sistema
    $config_status = [
        'php_version' => PHP_VERSION,
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'curl_available' => function_exists('curl_init'),
        'openssl_available' => extension_loaded('openssl')
    ];
    
    $diagnostic['system_status'] = $config_status;

    // 5. Generar recomendaciones
    $recommendations = [];
    
    if (!$diagnostic['database_status']['knowledge_files']['exists']) {
        $recommendations[] = 'Crear tabla knowledge_files en la base de datos';
    }
    
    if (!$diagnostic['database_status']['knowledge_base']['exists']) {
        $recommendations[] = 'Crear tabla knowledge_base en la base de datos';
    }
    
    if (!$diagnostic['file_system_status']['exists']) {
        $recommendations[] = 'Crear directorio de uploads para el usuario';
    }
    
    if (!$diagnostic['file_system_status']['writable']) {
        $recommendations[] = 'Verificar permisos de escritura en directorio de uploads';
    }
    
    $configured_providers = array_filter($diagnostic['ai_providers_status'], fn($p) => $p['configured']);
    if (empty($configured_providers)) {
        $recommendations[] = 'Configurar al menos un proveedor de IA (OpenAI, Gemini, etc.)';
    }
    
    if (!function_exists('curl_init')) {
        $recommendations[] = 'Instalar extensión cURL para comunicación con APIs';
    }
    
    if (!extension_loaded('openssl')) {
        $recommendations[] = 'Instalar extensión OpenSSL para cifrado';
    }

    $diagnostic['recommendations'] = $recommendations;

    // 6. Estado general del sistema
    $critical_issues = 0;
    $warnings = 0;
    
    if (!$diagnostic['database_status']['knowledge_files']['exists'] || 
        !$diagnostic['database_status']['knowledge_base']['exists']) {
        $critical_issues++;
    }
    
    if (!$diagnostic['file_system_status']['exists'] || 
        !$diagnostic['file_system_status']['writable']) {
        $critical_issues++;
    }
    
    if (empty($configured_providers)) {
        $warnings++;
    }
    
    if (!function_exists('curl_init') || !extension_loaded('openssl')) {
        $warnings++;
    }

    $diagnostic['overall_status'] = [
        'status' => $critical_issues === 0 ? ($warnings === 0 ? 'excellent' : 'good') : 'needs_attention',
        'critical_issues' => $critical_issues,
        'warnings' => $warnings,
        'ready_for_file_upload' => $critical_issues === 0,
        'ready_for_ai_processing' => $critical_issues === 0 && !empty($configured_providers)
    ];

    error_log("Diagnostic completed - Status: " . $diagnostic['overall_status']['status']);
    error_log("Critical issues: " . $critical_issues . ", Warnings: " . $warnings);

    json_out([
        'ok' => true,
        'diagnostic' => $diagnostic,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    error_log("Error in ai_file_system_diagnostic_safe.php: " . $e->getMessage());
    json_error('internal_error', 500, 'Internal server error: ' . $e->getMessage());
}
