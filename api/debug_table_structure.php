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
    
    // Verificar estructura de la tabla knowledge_base
    $sql = "DESCRIBE knowledge_base";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar datos existentes
    $sql = "SELECT COUNT(*) as total FROM knowledge_base";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $totalCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verificar datos por created_by (que parece ser la columna correcta)
    $sql = "SELECT created_by, COUNT(*) as count FROM knowledge_base GROUP BY created_by";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $byUser = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [
        'ok' => true,
        'user_id' => $user_id,
        'table_structure' => $columns,
        'total_records' => $totalCount['total'],
        'records_by_user' => $byUser,
        'message' => 'Estructura de tabla verificada'
    ];
    
    json_out($result);
    
} catch (Exception $e) {
    error_log("Error en debug_table_structure.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
?>
