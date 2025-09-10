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
    json_error('Usuario no autenticado', 401);
}

try {
    $pdo = db();
    
    // Verificación simple usando INFORMATION_SCHEMA
    $sql = "SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME IN ('knowledge_base', 'knowledge_files', 'ai_behavioral_patterns', 'ai_learning_metrics', 'ai_analysis_history', 'users')";
    
    $stmt = $pdo->query($sql);
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Verificar datos específicos del usuario
    $userData = [];
    
    if (in_array('knowledge_base', $existingTables)) {
        $sql = "SELECT COUNT(*) as count FROM knowledge_base WHERE created_by = " . intval($user_id);
        $stmt = $pdo->query($sql);
        $userData['knowledge_base'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    if (in_array('knowledge_files', $existingTables)) {
        $sql = "SELECT COUNT(*) as count FROM knowledge_files WHERE user_id = " . intval($user_id);
        $stmt = $pdo->query($sql);
        $userData['knowledge_files'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    if (in_array('ai_behavioral_patterns', $existingTables)) {
        $sql = "SELECT COUNT(*) as count FROM ai_behavioral_patterns WHERE user_id = " . intval($user_id);
        $stmt = $pdo->query($sql);
        $userData['ai_behavioral_patterns'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    if (in_array('ai_learning_metrics', $existingTables)) {
        $sql = "SELECT COUNT(*) as count FROM ai_learning_metrics WHERE user_id = " . intval($user_id);
        $stmt = $pdo->query($sql);
        $userData['ai_learning_metrics'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    if (in_array('ai_analysis_history', $existingTables)) {
        $sql = "SELECT COUNT(*) as count FROM ai_analysis_history WHERE user_id = " . intval($user_id);
        $stmt = $pdo->query($sql);
        $userData['ai_analysis_history'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    if (in_array('users', $existingTables)) {
        $sql = "SELECT COUNT(*) as count FROM users";
        $stmt = $pdo->query($sql);
        $userData['users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'existing_tables' => $existingTables,
        'user_data' => $userData,
        'hybrid_ready' => in_array('knowledge_base', $existingTables) && ($userData['knowledge_base'] ?? 0) > 0,
        'summary' => [
            'total_tables_found' => count($existingTables),
            'user_has_knowledge' => ($userData['knowledge_base'] ?? 0) > 0,
            'user_has_files' => ($userData['knowledge_files'] ?? 0) > 0,
            'user_has_patterns' => ($userData['ai_behavioral_patterns'] ?? 0) > 0
        ]
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en simple_db_check.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
