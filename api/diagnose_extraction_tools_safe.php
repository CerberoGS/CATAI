<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Verificar autenticación
$user = require_user();

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Método no permitido', 405);
}

try {
    $diagnostic = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $user['id'],
        'server_info' => [],
        'php_config' => [],
        'available_tools' => [],
        'file_permissions' => [],
        'test_results' => []
    ];
    
    // 1. Información del servidor
    $diagnostic['server_info'] = [
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'os' => PHP_OS,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size')
    ];
    
    // 2. Configuración PHP relevante
    $diagnostic['php_config'] = [
        'disabled_functions' => ini_get('disable_functions'),
        'shell_exec_available' => function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions'))),
        'exec_available' => function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions'))),
        'system_available' => function_exists('system') && !in_array('system', explode(',', ini_get('disable_functions'))),
        'passthru_available' => function_exists('passthru') && !in_array('passthru', explode(',', ini_get('disable_functions'))),
        'file_get_contents_available' => function_exists('file_get_contents'),
        'file_exists_available' => function_exists('file_exists'),
        'is_readable_available' => function_exists('is_readable')
    ];
    
    // 3. Verificar herramientas de extracción disponibles
    $tools = [
        'pdftotext' => 'pdftotext -v 2>&1',
        'catdoc' => 'catdoc -v 2>&1',
        'antiword' => 'antiword -v 2>&1',
        'poppler' => 'pdftotext -v 2>&1',
        'tesseract' => 'tesseract --version 2>&1'
    ];
    
    foreach ($tools as $tool => $command) {
        $diagnostic['available_tools'][$tool] = [
            'command' => $command,
            'available' => false,
            'version' => null,
            'error' => null
        ];
        
        if ($diagnostic['php_config']['shell_exec_available']) {
            try {
                $output = shell_exec($command);
                if ($output && !empty(trim($output))) {
                    $diagnostic['available_tools'][$tool]['available'] = true;
                    $diagnostic['available_tools'][$tool]['version'] = trim($output);
                } else {
                    $diagnostic['available_tools'][$tool]['error'] = 'No output from command';
                }
            } catch (Exception $e) {
                $diagnostic['available_tools'][$tool]['error'] = $e->getMessage();
            }
        } else {
            $diagnostic['available_tools'][$tool]['error'] = 'shell_exec not available';
        }
    }
    
    // 4. Verificar librerías PHP
    $libraries = [
        'TCPDF' => 'TCPDF',
        'FPDF' => 'FPDF',
        'PHPWord' => 'PhpOffice\\PhpWord\\IOFactory',
        'DomPDF' => 'Dompdf\\Dompdf',
        'mPDF' => 'Mpdf\\Mpdf'
    ];
    
    foreach ($libraries as $name => $class) {
        $diagnostic['available_tools'][$name] = [
            'available' => class_exists($class),
            'class' => $class
        ];
    }
    
    // 5. Verificar permisos de directorios
    $directories = [
        'uploads' => __DIR__ . '/uploads',
        'uploads_knowledge' => __DIR__ . '/uploads/knowledge',
        'logs' => __DIR__ . '/logs',
        'temp' => sys_get_temp_dir()
    ];
    
    foreach ($directories as $name => $path) {
        $diagnostic['file_permissions'][$name] = [
            'path' => $path,
            'exists' => file_exists($path),
            'is_dir' => is_dir($path),
            'is_readable' => is_readable($path),
            'is_writable' => is_writable($path),
            'permissions' => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : null
        ];
    }
    
    // 6. Crear directorio de uploads si no existe
    $uploadsDir = __DIR__ . '/uploads/knowledge';
    if (!is_dir($uploadsDir)) {
        if (mkdir($uploadsDir, 0755, true)) {
            $diagnostic['test_results']['created_uploads_dir'] = 'Successfully created uploads/knowledge directory';
        } else {
            $diagnostic['test_results']['created_uploads_dir'] = 'Failed to create uploads/knowledge directory';
        }
    }
    
    // 7. Verificar archivos de conocimiento existentes
    $stmt = db()->prepare("
        SELECT kf.id, kf.original_filename, kf.stored_filename, kf.file_type, kf.file_size, kf.extraction_status
        FROM knowledge_files kf
        WHERE kf.user_id = ?
        ORDER BY kf.created_at DESC
        LIMIT 5
    ");
    
    $stmt->execute([$user['id']]);
    $knowledgeFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $diagnostic['knowledge_files'] = [];
    foreach ($knowledgeFiles as $file) {
        $filePath = __DIR__ . "/uploads/knowledge/{$file['stored_filename']}";
        $diagnostic['knowledge_files'][] = [
            'id' => $file['id'],
            'original_filename' => $file['original_filename'],
            'stored_filename' => $file['stored_filename'],
            'file_type' => $file['file_type'],
            'file_size' => $file['file_size'],
            'extraction_status' => $file['extraction_status'],
            'file_exists' => file_exists($filePath),
            'file_path' => $filePath,
            'is_readable' => file_exists($filePath) ? is_readable($filePath) : false
        ];
    }
    
    // 8. Test de extracción básica (sin shell_exec)
    $diagnostic['test_results']['basic_extraction'] = [];
    
    // Buscar un archivo PDF para probar
    foreach ($diagnostic['knowledge_files'] as $file) {
        if ($file['file_type'] === 'pdf' && $file['file_exists']) {
            $filePath = $file['file_path'];
            
            // Test 1: Verificar que el archivo es realmente un PDF
            $fileHeader = file_get_contents($filePath, false, null, 0, 10);
            $isPdf = (strpos($fileHeader, '%PDF-') === 0);
            
            $diagnostic['test_results']['basic_extraction'][$file['original_filename']] = [
                'file_size' => filesize($filePath),
                'is_valid_pdf' => $isPdf,
                'file_header' => bin2hex($fileHeader),
                'can_read_file' => is_readable($filePath)
            ];
            
            // Test 2: Intentar leer metadatos básicos
            if ($isPdf) {
                $fileContent = file_get_contents($filePath, false, null, 0, 1024);
                $diagnostic['test_results']['basic_extraction'][$file['original_filename']]['first_1kb'] = 
                    substr($fileContent, 0, 200) . '...';
            }
            
            break; // Solo probar el primer PDF encontrado
        }
    }
    
    // 9. Recomendaciones
    $recommendations = [];
    
    if (!$diagnostic['php_config']['shell_exec_available']) {
        $recommendations[] = 'shell_exec está deshabilitado - necesitas acceso de administrador para habilitarlo';
    }
    
    if (!$diagnostic['available_tools']['pdftotext']['available']) {
        $recommendations[] = 'pdftotext no está instalado - contactar administrador del servidor';
    }
    
    if (!$diagnostic['available_tools']['TCPDF']['available']) {
        $recommendations[] = 'TCPDF no está disponible - considerar instalación vía Composer';
    }
    
    if (empty($diagnostic['knowledge_files'])) {
        $recommendations[] = 'No hay archivos de conocimiento para probar - subir un PDF primero';
    }
    
    $diagnostic['recommendations'] = $recommendations;
    
    // Respuesta exitosa
    json_out([
        'ok' => true,
        'message' => 'Diagnóstico de herramientas de extracción completado',
        'diagnostic' => $diagnostic
    ]);
    
} catch (Exception $e) {
    error_log("Error en diagnose_extraction_tools_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}
?>
