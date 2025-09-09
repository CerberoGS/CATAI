<?php
// /bolsa/api/favorites_safe.php
// Gestión de símbolos favoritos del usuario

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
        // Obtener favoritos
        $favoritesQuery = $pdo->prepare("
            SELECT symbol, name, added_at 
            FROM user_favorites 
            WHERE user_id = ? 
            ORDER BY added_at DESC
        ");
        $favoritesQuery->execute([$userId]);
        $favorites = $favoritesQuery->fetchAll();

        json_out([
            'ok' => true,
            'favorites' => $favorites
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Agregar/quitar favorito
        $input = json_input();
        $symbol = strtoupper(trim($input['symbol'] ?? ''));
        $action = $input['action'] ?? 'toggle'; // 'add', 'remove', 'toggle'

        if (empty($symbol)) {
            json_error('invalid_input', 400, 'Símbolo requerido');
        }

        // Verificar si ya existe
        $existsQuery = $pdo->prepare("SELECT id FROM user_favorites WHERE user_id = ? AND symbol = ?");
        $existsQuery->execute([$userId, $symbol]);
        $exists = $existsQuery->fetch();

        if ($action === 'add' || ($action === 'toggle' && !$exists)) {
            if ($exists) {
                json_error('already_exists', 400, 'Símbolo ya está en favoritos');
            }

            // Obtener nombre del símbolo desde universe
            $universePath = __DIR__ . '/../data/universe.json';
            $universe = [];
            if (file_exists($universePath)) {
                $universe = json_decode(file_get_contents($universePath), true) ?? [];
            }
            
            $symbolName = $symbol;
            foreach ($universe as $item) {
                if ($item['symbol'] === $symbol) {
                    $symbolName = $item['name'];
                    break;
                }
            }

            $insertQuery = $pdo->prepare("
                INSERT INTO user_favorites (user_id, symbol, name, added_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $insertQuery->execute([$userId, $symbol, $symbolName]);

            json_out([
                'ok' => true,
                'action' => 'added',
                'symbol' => $symbol,
                'message' => "Símbolo {$symbol} agregado a favoritos"
            ]);

        } elseif ($action === 'remove' || ($action === 'toggle' && $exists)) {
            if (!$exists) {
                json_error('not_found', 400, 'Símbolo no está en favoritos');
            }

            $deleteQuery = $pdo->prepare("DELETE FROM user_favorites WHERE user_id = ? AND symbol = ?");
            $deleteQuery->execute([$userId, $symbol]);

            json_out([
                'ok' => true,
                'action' => 'removed',
                'symbol' => $symbol,
                'message' => "Símbolo {$symbol} removido de favoritos"
            ]);
        }

    } else {
        json_error('method_not_allowed', 405, 'Método no permitido');
    }

} catch (Throwable $e) {
    json_error('server_error', 500, 'Error al gestionar favoritos');
}
