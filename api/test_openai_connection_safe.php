<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
    
    // Configurar límites extendidos
    set_time_limit(300);
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '512M');
    
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }
    
    // Verificar autenticación
    $user = require_user();
    if (!$user) {
        json_error('Token inválido');
    }
    
    // Obtener API key de OpenAI
    $openaiKey = get_api_key_for($user['id'], 'openai', 'OPENAI_API_KEY');
    if (!$openaiKey) {
        json_error('OpenAI API key no configurada');
    }
    
    $results = [
        'ok' => true,
        'message' => 'Test de conexión a OpenAI iniciado',
        'tests' => []
    ];
    
    // Test 1: Verificar API key
    $results['tests'][] = [
        'test' => 1,
        'name' => 'Verificar API key',
        'status' => 'success',
        'message' => 'OpenAI API key disponible',
        'key_masked' => substr($openaiKey, 0, 8) . '...' . substr($openaiKey, -4)
    ];
    
    // Test 2: Probar conexión básica a OpenAI
    $results['tests'][] = [
        'test' => 2,
        'name' => 'Probar conexión básica',
        'status' => 'processing',
        'message' => 'Probando conexión a OpenAI...'
    ];
    
    try {
        // Crear contexto con timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'method' => 'GET',
                'header' => [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: application/json'
                ]
            ]
        ]);
        
        // Probar endpoint de modelos (más ligero que files)
        $response = file_get_contents('https://api.openai.com/v1/models', false, $context);
        
        if ($response === false) {
            throw new Exception('No se pudo conectar a OpenAI API');
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception('Error de OpenAI: ' . $data['error']['message']);
        }
        
        $results['tests'][] = [
            'test' => 2,
            'name' => 'Probar conexión básica',
            'status' => 'success',
            'message' => 'Conexión a OpenAI exitosa',
            'models_count' => count($data['data'] ?? [])
        ];
        
    } catch (Exception $e) {
        $results['tests'][] = [
            'test' => 2,
            'name' => 'Probar conexión básica',
            'status' => 'error',
            'message' => 'Error en conexión: ' . $e->getMessage()
        ];
    }
    
    // Test 3: Probar endpoint de files (más pesado)
    $results['tests'][] = [
        'test' => 3,
        'name' => 'Probar endpoint de files',
        'status' => 'processing',
        'message' => 'Probando endpoint de files...'
    ];
    
    try {
        // Crear contexto con timeout extendido
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'method' => 'GET',
                'header' => [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: application/json'
                ]
            ]
        ]);
        
        // Probar endpoint de files
        $response = file_get_contents('https://api.openai.com/v1/files', false, $context);
        
        if ($response === false) {
            throw new Exception('No se pudo conectar al endpoint de files');
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception('Error en files: ' . $data['error']['message']);
        }
        
        $results['tests'][] = [
            'test' => 3,
            'name' => 'Probar endpoint de files',
            'status' => 'success',
            'message' => 'Endpoint de files accesible',
            'files_count' => count($data['data'] ?? [])
        ];
        
    } catch (Exception $e) {
        $results['tests'][] = [
            'test' => 3,
            'name' => 'Probar endpoint de files',
            'status' => 'error',
            'message' => 'Error en files: ' . $e->getMessage()
        ];
    }
    
    // Test 4: Probar endpoint de responses (más pesado aún)
    $results['tests'][] = [
        'test' => 4,
        'name' => 'Probar endpoint de responses',
        'status' => 'processing',
        'message' => 'Probando endpoint de responses...'
    ];
    
    try {
        // Crear contexto con timeout extendido
        $context = stream_context_create([
            'http' => [
                'timeout' => 90,
                'method' => 'GET',
                'header' => [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: application/json'
                ]
            ]
        ]);
        
        // Probar endpoint de responses (puede fallar si no hay responses)
        $response = file_get_contents('https://api.openai.com/v1/responses', false, $context);
        
        if ($response === false) {
            throw new Exception('No se pudo conectar al endpoint de responses');
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception('Error en responses: ' . $data['error']['message']);
        }
        
        $results['tests'][] = [
            'test' => 4,
            'name' => 'Probar endpoint de responses',
            'status' => 'success',
            'message' => 'Endpoint de responses accesible',
            'responses_count' => count($data['data'] ?? [])
        ];
        
    } catch (Exception $e) {
        $results['tests'][] = [
            'test' => 4,
            'name' => 'Probar endpoint de responses',
            'status' => 'error',
            'message' => 'Error en responses: ' . $e->getMessage()
        ];
    }
    
    $results['summary'] = [
        'total_tests' => 4,
        'successful_tests' => count(array_filter($results['tests'], fn($t) => $t['status'] === 'success')),
        'failed_tests' => count(array_filter($results['tests'], fn($t) => $t['status'] === 'error')),
        'recommendation' => 'Revisar tests fallidos para identificar problema específico'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en test_openai_connection_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
