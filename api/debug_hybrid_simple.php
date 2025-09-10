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
    
    // Verificar datos básicos
    $sql = "SELECT COUNT(*) as total FROM knowledge_base WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $knowledgeCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $sql = "SELECT COUNT(*) as total FROM knowledge_files WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $filesCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'knowledge_base_count' => $knowledgeCount['total'],
        'knowledge_files_count' => $filesCount['total'],
        'message' => 'Diagnóstico básico completado'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_hybrid_simple.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
