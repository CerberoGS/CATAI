<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $u = require_user();
    
    // Verificar que el usuario sea admin
    if (!($u['is_admin'] ?? false)) {
        json_out(['error' => 'unauthorized', 'message' => 'Solo administradores pueden modificar la estructura de tablas'], 403);
    }
    
    $pdo = db();
    
    // Eliminar las restricciones incorrectas
    $fixes = [];
    
    try {
        $pdo->exec('ALTER TABLE `user_news_api_keys` DROP INDEX `user_id`');
        $fixes[] = 'Eliminada restricción UNIQUE en user_id';
    } catch (Exception $e) {
        $fixes[] = 'No se pudo eliminar restricción user_id: ' . $e->getMessage();
    }
    
    try {
        $pdo->exec('ALTER TABLE `user_news_api_keys` DROP INDEX `provider_id`');
        $fixes[] = 'Eliminada restricción UNIQUE en provider_id';
    } catch (Exception $e) {
        $fixes[] = 'No se pudo eliminar restricción provider_id: ' . $e->getMessage();
    }
    
    // Agregar la restricción correcta: usuario + proveedor + origen debe ser único
    try {
        $pdo->exec('ALTER TABLE `user_news_api_keys` ADD UNIQUE KEY `uq_user_provider_origin` (`user_id`, `provider_id`, `origin`)');
        $fixes[] = 'Agregada restricción correcta: (user_id, provider_id, origin)';
    } catch (Exception $e) {
        $fixes[] = 'No se pudo agregar restricción correcta: ' . $e->getMessage();
    }
    
    json_out([
        'ok' => true,
        'message' => 'Estructura de tabla corregida',
        'fixes' => $fixes
    ]);
    
} catch (Throwable $e) {
    error_log("Error en fix_news_table_structure_safe.php: " . $e->getMessage());
    json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
