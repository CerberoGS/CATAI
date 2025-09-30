<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $user = require_user();
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$userId) {
        json_error('user_id_missing', 400, 'User ID not found in token');
    }
    
    $pdo = db();
    
    // Obtener el proveedor OpenAI
    $stmt = $pdo->prepare("SELECT id, ops_json FROM ai_providers WHERE slug = 'openai' LIMIT 1");
    $stmt->execute();
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        json_out(['ok' => false, 'error' => 'provider-not-found'], 404);
    }
    
    $ops = json_decode($provider['ops_json'], true);
    
    // Añadir la operación vs.get para Vector Stores (diferente de vs.get para Files)
    $ops['multi']['vs.get'] = [
        "method" => "GET",
        "url_override" => "https://api.openai.com/v1/vector_stores/{{VS_ID}}",
        "headers" => [
            ["name" => "Authorization", "value" => "Bearer {{API_KEY}}"],
            ["name" => "OpenAI-Beta", "value" => "assistants=v2"]
        ],
        "required_fields" => ["VS_ID"],
        "expected_status" => 200
    ];
    
    // Actualizar en la base de datos
    $updateStmt = $pdo->prepare("UPDATE ai_providers SET ops_json = ? WHERE id = ?");
    $updateStmt->execute([json_encode($ops), $provider['id']]);
    
    json_out([
        'ok' => true,
        'message' => 'Operación vs.get para Vector Stores añadida correctamente',
        'added_operation' => $ops['multi']['vs.get'],
        'note' => 'Esta es vs.get para Vector Stores (/vector_stores/{{VS_ID}}), diferente de vs.get para Files (/files/{{FILE_ID}})'
    ]);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage()
    ], 500);
}
