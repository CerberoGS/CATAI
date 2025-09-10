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
    
    // Información del usuario actual
    $user_info = [
        'user_id' => $user_id,
        'user_data' => $user,
        'source' => 'test_user_context.php'
    ];
    
    // Verificar archivos del usuario actual
    $stmt = $pdo->prepare("
        SELECT id, original_filename, stored_filename, user_id, created_at 
        FROM knowledge_files 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar conocimiento del usuario actual
    $stmt = $pdo->prepare("
        SELECT id, title, source_file, created_by, created_at 
        FROM knowledge_base 
        WHERE created_by = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user_id]);
    $user_knowledge = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar todos los usuarios y sus archivos (para comparación)
    $stmt = $pdo->prepare("
        SELECT 
            u.id as user_id,
            u.email,
            COUNT(kf.id) as file_count,
            GROUP_CONCAT(kf.original_filename) as files
        FROM users u
        LEFT JOIN knowledge_files kf ON u.id = kf.user_id
        GROUP BY u.id
        ORDER BY u.id
    ");
    $stmt->execute();
    $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar token en localStorage (simulado)
    $token_info = [
        'has_token' => !empty($_SERVER['HTTP_AUTHORIZATION']),
        'token_header' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'No token',
        'user_from_token' => $user_id
    ];
    
    $result = [
        'ok' => true,
        'current_user' => $user_info,
        'current_user_files' => $user_files,
        'current_user_knowledge' => $user_knowledge,
        'all_users_summary' => $all_users,
        'token_info' => $token_info,
        'analysis' => [
            'user_4_files' => array_filter($user_files, fn($f) => $f['user_id'] == 4),
            'user_8_files' => array_filter($user_files, fn($f) => $f['user_id'] == 8),
            'current_user_is_4' => $user_id == 4,
            'current_user_is_8' => $user_id == 8,
            'has_12_claves_file' => !empty(array_filter($user_files, fn($f) => strpos($f['original_filename'], '12_claves') !== false)),
            'has_patrones_file' => !empty(array_filter($user_files, fn($f) => strpos($f['original_filename'], 'Patrones') !== false))
        ],
        'recommendations' => []
    ];
    
    // Generar recomendaciones
    if ($user_id == 4 && !empty(array_filter($user_files, fn($f) => strpos($f['original_filename'], '12_claves') !== false))) {
        $result['recommendations'][] = 'Usuario 4 tiene archivo 12_claves - CORRECTO';
    } elseif ($user_id == 8 && !empty(array_filter($user_files, fn($f) => strpos($f['original_filename'], 'Patrones') !== false))) {
        $result['recommendations'][] = 'Usuario 8 tiene archivo Patrones - CORRECTO';
    } else {
        $result['recommendations'][] = 'POSIBLE INCONSISTENCIA: Usuario actual no tiene los archivos esperados';
    }
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en test_user_context.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
