<?php
/**
 * Generador de claves para el sistema de cifrado
 * Genera una clave KEK válida de 32 bytes en base64
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

json_header();

try {
    // 1) Autenticación
    $user = require_user();
    $userId = (int)($user['id'] ?? 0);
    
    if ($userId <= 0) {
        json_out(['error' => 'invalid-user'], 401);
        exit;
    }

    // 2) Generar clave KEK válida de 32 bytes
    $kekBytes = random_bytes(32); // 32 bytes = 256 bits
    $kekBase64 = base64_encode($kekBytes);
    
    // 3) Generar KID único
    $kid = 'k' . date('Y_m_d') . '_' . substr(bin2hex(random_bytes(4)), 0, 4);
    
    // 4) Estructura completa del keyring (formato correcto)
    $keyringStructure = [
        'version' => 1,
        'active_kid' => $kid,
        'keys' => [
            $kid => [
                'alg' => base64_encode(random_bytes(32)), // Algoritmo/identificador
                'kek_b64' => $kekBase64,
                'created_at' => date('c'), // ISO 8601 format
                'status' => 'active',
                'created_by' => 'system_generator'
            ]
        ]
    ];
    
    // 5) Información técnica
    $keyInfo = [
        'kid' => $kid,
        'kek_b64' => $kekBase64,
        'kek_length' => strlen($kekBytes),
        'kek_hex' => bin2hex($kekBytes),
        'base64_length' => strlen($kekBase64),
        'is_valid' => strlen($kekBytes) === 32
    ];

    json_out([
        'ok' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => [
            'id' => $userId,
            'email' => $user['email'] ?? 'N/A'
        ],
        'key_info' => $keyInfo,
        'keyring_json' => $keyringStructure,
        'instructions' => [
            'step_1' => 'Copia el JSON del keyring completo',
            'step_2' => 'Pega en el archivo: /home/u522228883/.secrets/catai.keyring.json',
            'step_3' => 'Verifica permisos: chmod 600 /home/u522228883/.secrets/catai.keyring.json',
            'step_4' => 'Prueba nuevamente el cifrado'
        ],
        'copy_ready' => [
            'keyring_complete' => json_encode($keyringStructure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'key_only' => $kekBase64,
            'kid_only' => $kid
        ]
    ]);

} catch (Exception $e) {
    json_error('Error generando clave: ' . $e->getMessage());
}
