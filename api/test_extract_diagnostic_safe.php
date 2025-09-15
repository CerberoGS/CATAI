<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jwt.php';

// Verificar autenticación
$user = require_user();

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

// Obtener datos del request
$input = read_json_body();
$knowledgeId = $input['knowledge_id'] ?? null;

if (!$knowledgeId) {
    json_error('ID de conocimiento requerido');
}

try {
    $diagnostic = [
        'timestamp' => date('Y-m-d H:i:s'),
        'knowledge_id' => $knowledgeId,
        'user_id' => $user['id'] ?? 0,
        'steps' => []
    ];
    
    // Paso 1: Verificar conocimiento en knowledge_base
    $stmt = db()->prepare("
        SELECT kb.*, kf.stored_filename, kf.file_type, kf.file_size
        FROM knowledge_base kb
        LEFT JOIN knowledge_files kf ON kf.original_filename = kb.source_file AND kf.user_id = ?
        WHERE kb.id = ? AND kb.created_by = ?
    ");
    
    $stmt->execute([$user['id'], $knowledgeId, $user['id']]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $diagnostic['steps']['1_knowledge_query'] = [
        'success' => $knowledge !== false,
        'found' => $knowledge !== false,
        'data' => $knowledge ? [
            'id' => $knowledge['id'],
            'source_file' => $knowledge['source_file'],
            'stored_filename' => $knowledge['stored_filename'],
            'file_type' => $knowledge['file_type'],
            'file_size' => $knowledge['file_size']
        ] : null
    ];
    
    if (!$knowledge) {
        $diagnostic['error'] = 'Conocimiento no encontrado';
        json_out(['ok' => false, 'diagnostic' => $diagnostic]);
        return;
    }
    
    // Paso 2: Verificar archivo físico (ruta correcta según flujo de subida)
    $userId = $user['id'] ?? 0;
    $filePath = __DIR__ . "/uploads/knowledge/" . intval($userId) . "/{$knowledge['stored_filename']}";
    $fileExists = file_exists($filePath);
    
    $diagnostic['steps']['2_file_check'] = [
        'success' => $fileExists,
        'file_path' => $filePath,
        'file_exists' => $fileExists,
        'file_size' => $fileExists ? filesize($filePath) : null
    ];
    
    if (!$fileExists) {
        $diagnostic['error'] = 'Archivo físico no encontrado';
        json_out(['ok' => false, 'diagnostic' => $diagnostic]);
        return;
    }
    
    // Paso 3: Probar extracción de contenido
    $extractedContent = '';
    $extractionMethod = 'none';
    
    if ($knowledge['file_type'] === 'pdf') {
        // Probar pdftotext
        if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
            $command = "pdftotext -layout \"$filePath\" - 2>/dev/null";
            $extractedContent = shell_exec($command);
            if ($extractedContent && strlen(trim($extractedContent)) > 50) {
                $extractionMethod = 'pdftotext';
                $extractedContent = trim($extractedContent);
            }
        }
        
        // Si no funcionó, usar fallback
        if (empty($extractedContent)) {
            $extractedContent = "CONTENIDO PDF DE: {$knowledge['source_file']}\n\n" .
                               "TAMAÑO: " . round($knowledge['file_size'] / 1024 / 1024, 2) . " MB\n\n" .
                               "ESTADO: Extracción automática no disponible\n\n" .
                               "NOTA: Para extracción completa de contenido PDF se requiere:\n" .
                               "1. Instalación de pdftotext en el servidor\n" .
                               "2. O librería TCPDF/Poppler en PHP\n" .
                               "3. Contactar al administrador del servidor\n\n" .
                               "El archivo ha sido subido correctamente y está disponible para análisis manual.";
            $extractionMethod = 'fallback';
        }
    } else {
        $extractedContent = "Archivo de tipo {$knowledge['file_type']} - extracción no implementada";
        $extractionMethod = 'not_implemented';
    }
    
    $diagnostic['steps']['3_extraction'] = [
        'success' => !empty($extractedContent),
        'method' => $extractionMethod,
        'content_length' => strlen($extractedContent),
        'word_count' => str_word_count($extractedContent)
    ];
    
    // Paso 4: Generar resumen
    $summary = generateSummary($extractedContent);
    
    $diagnostic['steps']['4_summary'] = [
        'success' => !empty($summary),
        'summary_length' => strlen($summary)
    ];
    
    // Respuesta exitosa
    json_out([
        'ok' => true,
        'message' => 'Diagnóstico completado exitosamente',
        'content' => $extractedContent,
        'summary' => $summary,
        'file_info' => [
            'original_filename' => $knowledge['source_file'],
            'stored_filename' => $knowledge['stored_filename'],
            'file_type' => $knowledge['file_type'],
            'file_size' => $knowledge['file_size'],
            'file_size_mb' => round($knowledge['file_size'] / 1024 / 1024, 2)
        ],
        'extraction_info' => [
            'content_length' => strlen($extractedContent),
            'word_count' => str_word_count($extractedContent),
            'extraction_timestamp' => date('Y-m-d H:i:s'),
            'summary_length' => strlen($summary),
            'extraction_method' => $extractionMethod
        ],
        'diagnostic' => $diagnostic
    ]);
    
} catch (Exception $e) {
    error_log("Error en test_extract_diagnostic_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage());
}

function generateSummary($content) {
    $content = trim($content);
    
    if (empty($content)) {
        return 'Sin contenido extraído disponible';
    }
    
    // Si el contenido es muy corto, devolverlo completo
    if (strlen($content) <= 300) {
        return $content;
    }
    
    // Buscar el primer párrafo completo
    $paragraphs = explode("\n\n", $content);
    $firstParagraph = trim($paragraphs[0]);
    
    // Si el primer párrafo es muy largo, truncarlo
    if (strlen($firstParagraph) > 300) {
        $summary = substr($firstParagraph, 0, 300);
        // Buscar el último espacio para no cortar palabras
        $lastSpace = strrpos($summary, ' ');
        if ($lastSpace !== false) {
            $summary = substr($summary, 0, $lastSpace);
        }
        return $summary . '...';
    }
    
    // Si el primer párrafo es adecuado, usarlo
    if (strlen($firstParagraph) >= 50) {
        return $firstParagraph;
    }
    
    // Fallback: primeros 200 caracteres
    $summary = substr($content, 0, 200);
    $lastSpace = strrpos($summary, ' ');
    if ($lastSpace !== false) {
        $summary = substr($summary, 0, $lastSpace);
    }
    
    return $summary . '...';
}
?>
