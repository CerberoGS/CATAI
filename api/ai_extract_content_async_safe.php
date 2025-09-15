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
    
    // Configurar timeout extendido
    set_time_limit(300); // 5 minutos
    ini_set('max_execution_time', 300);
    
    $results = [
        'ok' => true,
        'message' => 'Procesamiento iniciado con timeout extendido',
        'file_info' => [
            'id' => $file['id'],
            'filename' => $file['original_filename'],
            'size' => $file['file_size'],
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
    
    // Paso 3: Subir archivo a OpenAI (con timeout extendido)
    $results['processing'][] = [
        'step' => 3,
        'name' => 'Subir archivo a OpenAI',
        'status' => 'processing',
        'message' => 'Subiendo archivo a OpenAI...'
    ];
    
    try {
        // Crear contexto con timeout extendido
        $context = stream_context_create([
            'http' => [
                'timeout' => 120, // 2 minutos para subida
                'method' => 'POST',
                'header' => [
                    'Authorization: Bearer ' . $openaiKey,
                    'Content-Type: multipart/form-data'
                ]
            ]
        ]);
        
        // Simular subida (en producción, aquí iría la llamada real)
        $results['processing'][] = [
            'step' => 3,
            'name' => 'Subir archivo a OpenAI',
            'status' => 'success',
            'message' => 'Archivo subido exitosamente (simulado)',
            'openai_file_id' => 'file-abc123xyz'
        ];
        
        // Paso 4: Análisis con IA (con timeout extendido)
        $results['processing'][] = [
            'step' => 4,
            'name' => 'Análisis con IA',
            'status' => 'processing',
            'message' => 'Analizando contenido con IA...'
        ];
        
        // Simular análisis (en producción, aquí iría la llamada real)
        $results['processing'][] = [
            'step' => 4,
            'name' => 'Análisis con IA',
            'status' => 'success',
            'message' => 'Análisis completado exitosamente (simulado)',
            'content_extracted' => 'Contenido extraído del PDF...',
            'summary' => 'Resumen del análisis...'
        ];
        
        // Paso 5: Guardar en base de datos
        $results['processing'][] = [
            'step' => 5,
            'name' => 'Guardar en base de datos',
            'status' => 'processing',
            'message' => 'Guardando resultados...'
        ];
        
        // Simular guardado (en producción, aquí iría el INSERT real)
        $results['processing'][] = [
            'step' => 5,
            'name' => 'Guardar en base de datos',
            'status' => 'success',
            'message' => 'Resultados guardados exitosamente (simulado)'
        ];
        
        $results['summary'] = [
            'total_steps' => 5,
            'completed_steps' => 5,
            'processing_time' => 'Simulado - 30-60 segundos en producción',
            'recommendation' => 'Procesamiento completado exitosamente'
        ];
        
    } catch (Exception $e) {
        $results['processing'][] = [
            'step' => 3,
            'name' => 'Subir archivo a OpenAI',
            'status' => 'error',
            'message' => 'Error en subida: ' . $e->getMessage()
        ];
        
        $results['error'] = $e->getMessage();
    }
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en ai_extract_content_async_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}
?>
