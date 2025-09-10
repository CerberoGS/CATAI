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
    
    // 1. Listar usuarios con conocimiento
    $sql = "SELECT DISTINCT u.id, u.email, u.name, 
                   COUNT(kb.id) as knowledge_count,
                   COUNT(kf.id) as files_count,
                   COUNT(abp.id) as behavioral_patterns_count,
                   COUNT(aah.id) as analysis_history_count
            FROM users u
            LEFT JOIN knowledge_base kb ON u.id = kb.created_by
            LEFT JOIN knowledge_files kf ON u.id = kf.user_id
            LEFT JOIN ai_behavioral_patterns abp ON u.id = abp.user_id
            LEFT JOIN ai_analysis_history aah ON u.id = aah.user_id
            GROUP BY u.id, u.email, u.name
            HAVING COUNT(kb.id) > 0 OR COUNT(kf.id) > 0 OR COUNT(abp.id) > 0 OR COUNT(aah.id) > 0
            ORDER BY (COUNT(kb.id) + COUNT(kf.id) + COUNT(abp.id) + COUNT(aah.id)) DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $usersWithContext = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Detalles del usuario actual
    $sql = "SELECT u.id, u.email, u.name,
                   COUNT(kb.id) as knowledge_count,
                   COUNT(kf.id) as files_count,
                   COUNT(abp.id) as behavioral_patterns_count,
                   COUNT(aah.id) as analysis_history_count
            FROM users u
            LEFT JOIN knowledge_base kb ON u.id = kb.created_by
            LEFT JOIN knowledge_files kf ON u.id = kf.user_id
            LEFT JOIN ai_behavioral_patterns abp ON u.id = abp.user_id
            LEFT JOIN ai_analysis_history aah ON u.id = aah.user_id
            WHERE u.id = ?
            GROUP BY u.id, u.email, u.name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. Usuarios con más contexto (top 3)
    $topUsers = array_slice($usersWithContext, 0, 3);
    
    $result = [
        'ok' => true,
        'current_user' => $currentUser,
        'users_with_context' => $usersWithContext,
        'top_users' => $topUsers,
        'total_users_with_context' => count($usersWithContext),
        'message' => 'Usuarios con contexto encontrados'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_users_with_context.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
