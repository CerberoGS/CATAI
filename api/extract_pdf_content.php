<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

// Verificar autenticación
$user = require_user();
$user_id = $user['user_id'] ?? $user['id'] ?? null;

if (!$user_id) {
    json_error('Usuario no válido', 400);
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['knowledge_id'])) {
        json_error('ID de conocimiento requerido', 400);
    }

    $knowledge_id = (int)$input['knowledge_id'];
    $pdo = db();

    // Obtener información del conocimiento
    $stmt = $pdo->prepare("
        SELECT kb.*, kf.stored_filename, kf.file_type, kf.file_size
        FROM knowledge_base kb
        LEFT JOIN knowledge_files kf ON kf.original_filename = kb.source_file AND kf.user_id = ?
        WHERE kb.id = ? AND kb.created_by = ?
    ");
    
    $stmt->execute([$user_id, $knowledge_id, $user_id]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$knowledge) {
        json_error('Conocimiento no encontrado', 404);
    }

    if (!$knowledge['stored_filename']) {
        json_error('Archivo no encontrado', 404);
    }

    // Ruta del archivo según el flujo de subida real
    $filePath = __DIR__ . "/uploads/knowledge/{$user_id}/{$knowledge['stored_filename']}";
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado: ' . $filePath, 404);
    }

    $extractedContent = '';
    $extractedSummary = '';

    // Extraer contenido según el tipo de archivo
    switch ($knowledge['file_type']) {
        case 'pdf':
            $extractedContent = extractPDFContent($filePath);
            break;
        case 'txt':
            $extractedContent = file_get_contents($filePath);
            break;
        case 'doc':
        case 'docx':
            $extractedContent = extractWordContent($filePath);
            break;
        default:
            $extractedContent = 'Tipo de archivo no soportado para extracción automática';
    }

    // Generar resumen automático
    $extractedSummary = generateSummary($extractedContent);

    // Actualizar la base de datos con el contenido extraído
    $stmt = $pdo->prepare("
        UPDATE knowledge_base 
        SET content = ?, summary = ?, updated_at = NOW()
        WHERE id = ? AND created_by = ?
    ");
    
    $stmt->execute([$extractedContent, $extractedSummary, $knowledge_id, $user_id]);

    // Actualizar el estado de extracción en knowledge_files
    $stmt = $pdo->prepare("
        UPDATE knowledge_files 
        SET extraction_status = 'completed', extracted_items = 1, updated_at = NOW()
        WHERE original_filename = ? AND user_id = ?
    ");
    
    $stmt->execute([$knowledge['source_file'], $user_id]);

    json_out([
        'ok' => true,
        'message' => 'Contenido extraído exitosamente',
        'content' => $extractedContent,
        'summary' => $extractedSummary,
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
            'summary_length' => strlen($extractedSummary)
        ]
    ]);

} catch (Exception $e) {
    error_log("Error en extract_pdf_content.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

function extractPDFContent($filePath) {
    $extractedContent = '';
    $extractionMethod = '';
    
    // Método 1: pdftotext (comando shell - más efectivo)
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        $command = "pdftotext -layout \"$filePath\" - 2>/dev/null";
        $extractedContent = shell_exec($command);
        if ($extractedContent && strlen(trim($extractedContent)) > 50) {
            $extractionMethod = 'pdftotext';
            return trim($extractedContent);
        }
    }
    
    // Método 2: TCPDF (si está disponible)
    if (class_exists('TCPDF')) {
        try {
            $pdf = new TCPDF();
            $pdf->setSourceFile($filePath);
            $pageCount = $pdf->getNumPages();
            
            for ($i = 1; $i <= min($pageCount, 5); $i++) { // Limitar a 5 páginas
                $page = $pdf->importPage($i);
                $text = $page->getText();
                $extractedContent .= $text . "\n\n";
            }
            
            if (strlen(trim($extractedContent)) > 50) {
                $extractionMethod = 'TCPDF';
                return trim($extractedContent);
            }
        } catch (Exception $e) {
            error_log("Error con TCPDF: " . $e->getMessage());
        }
    }
    
    // Método 3: Poppler (alternativa)
    if (function_exists('shell_exec')) {
        $command = "pdftotext -nopgbrk \"$filePath\" - 2>/dev/null";
        $extractedContent = shell_exec($command);
        if ($extractedContent && strlen(trim($extractedContent)) > 50) {
            $extractionMethod = 'poppler';
            return trim($extractedContent);
        }
    }
    
    // Fallback: contenido simulado con información del archivo
    $fileInfo = [
        'filename' => basename($filePath),
        'size' => filesize($filePath),
        'size_mb' => round(filesize($filePath) / 1024 / 1024, 2)
    ];
    
    return "CONTENIDO PDF DE: {$fileInfo['filename']}\n\n" .
           "TAMAÑO: {$fileInfo['size_mb']} MB\n\n" .
           "ESTADO: Extracción automática no disponible\n\n" .
           "NOTA: Para extracción completa de contenido PDF se requiere:\n" .
           "1. Instalación de pdftotext en el servidor\n" .
           "2. O librería TCPDF/Poppler en PHP\n" .
           "3. Contactar al administrador del servidor\n\n" .
           "El archivo ha sido subido correctamente y está disponible para análisis manual.";
}

function extractWordContent($filePath) {
    $extractedContent = '';
    
    // Método 1: catdoc (comando shell - para archivos .doc)
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        $command = "catdoc \"$filePath\" 2>/dev/null";
        $extractedContent = shell_exec($command);
        if ($extractedContent && strlen(trim($extractedContent)) > 50) {
            return trim($extractedContent);
        }
    }
    
    // Método 2: antiword (alternativa para .doc)
    if (function_exists('shell_exec')) {
        $command = "antiword \"$filePath\" 2>/dev/null";
        $extractedContent = shell_exec($command);
        if ($extractedContent && strlen(trim($extractedContent)) > 50) {
            return trim($extractedContent);
        }
    }
    
    // Método 3: PHPWord (para archivos .docx)
    if (class_exists('PhpOffice\PhpWord\IOFactory')) {
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
            $extractedContent = '';
            
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
                        foreach ($element->getElements() as $text) {
                            if ($text instanceof \PhpOffice\PhpWord\Element\Text) {
                                $extractedContent .= $text->getText() . ' ';
                            }
                        }
                    }
                }
            }
            
            if (strlen(trim($extractedContent)) > 50) {
                return trim($extractedContent);
            }
        } catch (Exception $e) {
            error_log("Error con PHPWord: " . $e->getMessage());
        }
    }
    
    // Fallback: contenido simulado con información del archivo
    $fileInfo = [
        'filename' => basename($filePath),
        'size' => filesize($filePath),
        'size_mb' => round(filesize($filePath) / 1024 / 1024, 2)
    ];
    
    return "CONTENIDO WORD DE: {$fileInfo['filename']}\n\n" .
           "TAMAÑO: {$fileInfo['size_mb']} MB\n\n" .
           "ESTADO: Extracción automática no disponible\n\n" .
           "NOTA: Para extracción completa de contenido Word se requiere:\n" .
           "1. Instalación de catdoc/antiword en el servidor\n" .
           "2. O librería PHPWord en PHP\n" .
           "3. Contactar al administrador del servidor\n\n" .
           "El archivo ha sido subido correctamente y está disponible para análisis manual.";
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
