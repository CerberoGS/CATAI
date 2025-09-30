<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    $userId = (int)($u['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error'=>'invalid-user'], 401);
    }
    
    $in = json_input(true) ?: [];
    $recordId = (int)($in['id'] ?? 0);
    
    if ($recordId <= 0) {
        json_out(['error' => 'invalid-input', 'message' => 'ID de registro es requerido'], 400);
    }
    
    $pdo = db();
    
    // BORRAR DIRECTAMENTE por ID del registro en user_news_api_keys
    $stmt = $pdo->prepare('DELETE FROM user_news_api_keys WHERE id = ? AND user_id = ?');
    $stmt->execute([$recordId, $userId]);
    
    if ($stmt->rowCount() > 0) {
        json_out(['ok' => true, 'message' => 'Proveedor eliminado exitosamente']);
    } else {
        json_out(['error' => 'not-found', 'message' => 'No se encontró el proveedor'], 404);
    }
    
} catch (Throwable $e) {
    error_log("Error en delete_user_news_key_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => 'Error interno del servidor'], 500);
}
