<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
    header_remove('X-Powered-By');
    apply_cors();
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
    
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Método no permitido', 405);
    }
    
    // Verificar autenticación
    $user = require_user();
    if (!$user) {
        json_error('Token inválido');
    }
    
    // Obtener datos del request
    $input = json_input(true);
    $fileId = $input['file_id'] ?? null;
    
    if (!$fileId) {
        json_error('file_id requerido');
    }
    
    // Verificar que el archivo existe
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM knowledge_files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $user['id']]);
    $file = $stmt->fetch();
    
    if (!$file) {
        json_error('Archivo no encontrado o no tienes permisos');
    }
    
    // Obtener API key de OpenAI
    $openaiKey = get_api_key_for($user['id'], 'openai', 'OPENAI_API_KEY');
    if (!$openaiKey) {
        json_error('OpenAI API key no configurada');
    }
    
    // Simular el procesamiento paso a paso
    $results = [
        'ok' => true,
        'steps' => [],
        'file_info' => [
            'id' => $file['id'],
            'filename' => $file['original_filename'],
            'size' => $file['file_size'],
            'status' => $file['upload_status']
        ]
    ];
    
    // Paso 1: Verificar archivo
    $results['steps'][] = [
        'step' => 1,
        'name' => 'Verificar archivo',
        'status' => 'success',
        'message' => 'Archivo encontrado y accesible'
    ];
    
    // Paso 2: Verificar API key
    $results['steps'][] = [
        'step' => 2,
        'name' => 'Verificar API key',
        'status' => 'success',
        'message' => 'OpenAI API key disponible'
    ];
    
    // Paso 3: Simular subida a OpenAI (sin hacer la llamada real)
    $results['steps'][] = [
        'step' => 3,
        'name' => 'Subir archivo a OpenAI',
        'status' => 'simulated',
        'message' => 'Simulado - no se hizo llamada real a OpenAI',
        'note' => 'Para probar la llamada real, usar el endpoint principal'
    ];
    
    // Paso 4: Simular análisis
    $results['steps'][] = [
        'step' => 4,
        'name' => 'Análisis con IA',
        'status' => 'simulated',
        'message' => 'Simulado - no se hizo llamada real a OpenAI',
        'note' => 'Para probar el análisis real, usar el endpoint principal'
    ];
    
    // Paso 5: Simular guardado
    $results['steps'][] = [
        'step' => 5,
        'name' => 'Guardar en base de datos',
        'status' => 'simulated',
        'message' => 'Simulado - no se guardó en la base de datos',
        'note' => 'Para probar el guardado real, usar el endpoint principal'
    ];
    
    $results['summary'] = [
        'total_steps' => 5,
        'completed_steps' => 2,
        'simulated_steps' => 3,
        'estimated_time' => '30-60 segundos para procesamiento real',
        'recommendation' => 'El endpoint principal debería funcionar correctamente'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en test_ai_processing_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
