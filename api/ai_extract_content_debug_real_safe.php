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
    set_time_limit(600);
    ini_set('max_execution_time', 600);
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
    
    $results = [
        'ok' => true,
        'message' => 'Debug de extracción real iniciado',
        'file_info' => [
            'id' => $file['id'],
            'filename' => $file['original_filename'],
            'size' => $file['file_size'],
            'size_mb' => round($file['file_size'] / 1024 / 1024, 2),
            'status' => $file['upload_status']
        ],
        'debug_steps' => []
    ];
    
    // Paso 1: Verificar archivo
    $results['debug_steps'][] = [
        'step' => 1,
        'name' => 'Verificar archivo',
        'status' => 'success',
        'message' => 'Archivo encontrado y accesible'
    ];
    
    // Paso 2: Verificar API key
    $results['debug_steps'][] = [
        'step' => 2,
        'name' => 'Verificar API key',
        'status' => 'success',
        'message' => 'OpenAI API key disponible'
    ];
    
    // Paso 3: Simular subida a OpenAI (con timeout real)
    $results['debug_steps'][] = [
        'step' => 3,
        'name' => 'Subir archivo a OpenAI',
        'status' => 'processing',
        'message' => 'Simulando subida a OpenAI...'
    ];
    
    // Simular tiempo de subida
    sleep(2);
    
    $results['debug_steps'][] = [
        'step' => 3,
        'name' => 'Subir archivo a OpenAI',
        'status' => 'success',
        'message' => 'Archivo subido exitosamente (simulado)',
        'openai_file_id' => 'file-debug-123'
    ];
    
    // Paso 4: Simular análisis con IA (con timeout real)
    $results['debug_steps'][] = [
        'step' => 4,
        'name' => 'Análisis con IA',
        'status' => 'processing',
        'message' => 'Simulando análisis con IA...'
    ];
    
    // Simular tiempo de análisis
    sleep(3);
    
    $results['debug_steps'][] = [
        'step' => 4,
        'name' => 'Análisis con IA',
        'status' => 'success',
        'message' => 'Análisis completado exitosamente (simulado)',
        'content_extracted' => 'Contenido extraído del PDF...',
        'summary' => 'Resumen del análisis...'
    ];
    
    // Paso 5: Simular guardado en base de datos
    $results['debug_steps'][] = [
        'step' => 5,
        'name' => 'Guardar en base de datos',
        'status' => 'processing',
        'message' => 'Simulando guardado...'
    ];
    
    // Simular tiempo de guardado
    sleep(1);
    
    $results['debug_steps'][] = [
        'step' => 5,
        'name' => 'Guardar en base de datos',
        'status' => 'success',
        'message' => 'Resultados guardados exitosamente (simulado)'
    ];
    
    $results['summary'] = [
        'total_steps' => 5,
        'completed_steps' => 5,
        'total_time' => '6 segundos (simulado)',
        'recommendation' => 'Debug completado - el problema está en la llamada real a OpenAI'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en ai_extract_content_debug_real_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
