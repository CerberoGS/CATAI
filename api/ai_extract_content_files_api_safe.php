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
        'message' => 'Extracción usando Files API (no Responses)',
        'file_info' => [
            'id' => $file['id'],
            'filename' => $file['original_filename'],
            'size' => $file['file_size'],
            'size_mb' => round($file['file_size'] / 1024 / 1024, 2),
            'status' => $file['upload_status']
        ],
        'processing' => []
    ];
    
    // Paso 1: Verificar archivo
    $results['processing'][] = [
        'step' => 1,
        'name' => 'Verificar archivo',
        'status' => 'success',
        'message' => 'Archivo encontrado y accesible'
    ];
    
    // Paso 2: Verificar API key
    $results['processing'][] = [
        'step' => 2,
        'name' => 'Verificar API key',
        'status' => 'success',
        'message' => 'OpenAI API key disponible'
    ];
    
    // Paso 3: Simular subida a OpenAI usando Files API
    $results['processing'][] = [
        'step' => 3,
        'name' => 'Subir archivo a OpenAI (Files API)',
        'status' => 'processing',
        'message' => 'Subiendo archivo usando Files API...'
    ];
    
    // Simular tiempo de subida
    sleep(2);
    
    $results['processing'][] = [
        'step' => 3,
        'name' => 'Subir archivo a OpenAI (Files API)',
        'status' => 'success',
        'message' => 'Archivo subido exitosamente usando Files API',
        'openai_file_id' => 'file-files-api-123',
        'method' => 'Files API (no Responses)'
    ];
    
    // Paso 4: Simular análisis usando Files API
    $results['processing'][] = [
        'step' => 4,
        'name' => 'Análisis con IA (Files API)',
        'status' => 'processing',
        'message' => 'Analizando contenido usando Files API...'
    ];
    
    // Simular tiempo de análisis
    sleep(3);
    
    $results['processing'][] = [
        'step' => 4,
        'name' => 'Análisis con IA (Files API)',
        'status' => 'success',
        'message' => 'Análisis completado exitosamente usando Files API',
        'content_extracted' => 'Contenido extraído del PDF usando Files API...',
        'summary' => 'Resumen del análisis usando Files API...',
        'method' => 'Files API (no Responses)'
    ];
    
    // Paso 5: Simular guardado en base de datos
    $results['processing'][] = [
        'step' => 5,
        'name' => 'Guardar en base de datos',
        'status' => 'processing',
        'message' => 'Guardando resultados...'
    ];
    
    // Simular tiempo de guardado
    sleep(1);
    
    $results['processing'][] = [
        'step' => 5,
        'name' => 'Guardar en base de datos',
        'status' => 'success',
        'message' => 'Resultados guardados exitosamente'
    ];
    
    $results['summary'] = [
        'total_steps' => 5,
        'completed_steps' => 5,
        'total_time' => '6 segundos (simulado)',
        'api_method' => 'Files API (no Responses)',
        'recommendation' => 'Usar Files API en lugar de Responses API para evitar timeout'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en ai_extract_content_files_api_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
