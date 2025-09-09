<?php
// /bolsa/api/notifications_safe.php
// Sistema de notificaciones para el usuario

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
json_header();

try {
    $user = require_user();
    $userId = (int)$user['id'];

    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Obtener notificaciones
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $unread_only = ($_GET['unread_only'] ?? 'false') === 'true';

        $whereClause = $unread_only ? 'AND is_read = 0' : '';
        
        $notificationsQuery = $pdo->prepare("
            SELECT id, title, message, type, is_read, created_at, data
            FROM user_notifications 
            WHERE user_id = ? {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $notificationsQuery->execute([$userId, $limit, $offset]);

        // Contar totales
        $countQuery = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread
            FROM user_notifications 
            WHERE user_id = ?
        ");
        $countQuery->execute([$userId]);
        $counts = $countQuery->fetch();

        json_out([
            'ok' => true,
            'notifications' => $notificationsQuery->fetchAll(),
            'counts' => [
                'total' => (int)$counts['total'],
                'unread' => (int)$counts['unread']
            ]
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_input();
        $action = $input['action'] ?? '';

        if ($action === 'mark_read') {
            // Marcar como leída
            $notificationId = (int)($input['id'] ?? 0);
            
            if ($notificationId > 0) {
                $updateQuery = $pdo->prepare("
                    UPDATE user_notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE id = ? AND user_id = ?
                ");
                $updateQuery->execute([$notificationId, $userId]);
            } else {
                // Marcar todas como leídas
                $updateQuery = $pdo->prepare("
                    UPDATE user_notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE user_id = ? AND is_read = 0
                ");
                $updateQuery->execute([$userId]);
            }

            json_out([
                'ok' => true,
                'message' => 'Notificación marcada como leída'
            ]);

        } elseif ($action === 'create') {
            // Crear notificación (solo para admin o sistema)
            $title = trim($input['title'] ?? '');
            $message = trim($input['message'] ?? '');
            $type = $input['type'] ?? 'info';
            $targetUserId = (int)($input['user_id'] ?? $userId);

            if (empty($title) || empty($message)) {
                json_error('invalid_input', 400, 'Título y mensaje requeridos');
            }

            // Verificar permisos (solo admin puede crear para otros usuarios)
            if ($targetUserId !== $userId) {
                $isAdmin = !!(($user['is_admin'] ?? false) || 
                             (($user['role'] ?? '') === 'admin'));
                if (!$isAdmin) {
                    json_error('forbidden', 403, 'No autorizado');
                }
            }

            $insertQuery = $pdo->prepare("
                INSERT INTO user_notifications (user_id, title, message, type, created_at, data) 
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $data = json_encode($input['data'] ?? []);
            $insertQuery->execute([$targetUserId, $title, $message, $type, $data]);

            json_out([
                'ok' => true,
                'message' => 'Notificación creada',
                'id' => $pdo->lastInsertId()
            ]);

        } else {
            json_error('invalid_action', 400, 'Acción no válida');
        }

    } else {
        json_error('method_not_allowed', 405, 'Método no permitido');
    }

} catch (Throwable $e) {
    json_error('server_error', 500, 'Error en sistema de notificaciones');
}
