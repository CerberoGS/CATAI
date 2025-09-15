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

try {
    $input = read_json_body();
    
    if (!$input || !isset($input['knowledge_id'])) {
        json_error('ID de conocimiento requerido', 400);
    }

    $knowledge_id = (int)$input['knowledge_id'];
    $user_id = $user['id'];
    $pdo = db();

    // Obtener información del conocimiento
    $stmt = $pdo->prepare("
        SELECT kb.*, kf.stored_filename, kf.file_type, kf.file_size, kf.original_filename
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

    // Buscar archivo en la ruta correcta según el flujo de subida
    $filePath = __DIR__ . "/uploads/knowledge/{$user_id}/{$knowledge['stored_filename']}";
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado: ' . $filePath, 404);
    }

    $extractedContent = '';
    $extractionMethod = '';
    $extractionSuccess = false;

    // Extraer contenido según el tipo de archivo
    switch (strtolower($knowledge['file_type'])) {
        case 'pdf':
            $result = extractPDFContentHybrid($filePath);
            $extractedContent = $result['content'];
            $extractionMethod = $result['method'];
            $extractionSuccess = $result['success'];
            break;
            
        case 'txt':
            $extractedContent = file_get_contents($filePath);
            $extractionMethod = 'file_get_contents';
            $extractionSuccess = !empty($extractedContent);
            break;
            
        case 'doc':
        case 'docx':
            $result = extractWordContentHybrid($filePath);
            $extractedContent = $result['content'];
            $extractionMethod = $result['method'];
            $extractionSuccess = $result['success'];
            break;
            
        case 'csv':
            $extractedContent = file_get_contents($filePath);
            $extractionMethod = 'file_get_contents';
            $extractionSuccess = !empty($extractedContent);
            break;
            
        default:
            $extractedContent = "Tipo de archivo '{$knowledge['file_type']}' no soportado para extracción automática";
            $extractionMethod = 'unsupported';
            $extractionSuccess = false;
    }

    // Generar resumen inteligente
    $extractedSummary = generateIntelligentSummary($extractedContent, $knowledge['file_type']);

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
        SET extraction_status = ?, extracted_items = 1, updated_at = NOW()
        WHERE original_filename = ? AND user_id = ?
    ");
    
    $status = $extractionSuccess ? 'completed' : 'partial';
    $stmt->execute([$status, $knowledge['source_file'], $user_id]);

    json_out([
        'ok' => true,
        'message' => 'Contenido extraído exitosamente',
        'content' => $extractedContent,
        'summary' => $extractedSummary,
        'file_info' => [
            'original_filename' => $knowledge['original_filename'] ?? $knowledge['source_file'],
            'stored_filename' => $knowledge['stored_filename'],
            'file_type' => $knowledge['file_type'],
            'file_size' => $knowledge['file_size'],
            'file_size_mb' => round($knowledge['file_size'] / 1024 / 1024, 2),
            'file_path' => $filePath
        ],
        'extraction_info' => [
            'content_length' => strlen($extractedContent),
            'word_count' => str_word_count($extractedContent),
            'extraction_timestamp' => date('Y-m-d H:i:s'),
            'summary_length' => strlen($extractedSummary),
            'extraction_method' => $extractionMethod,
            'extraction_success' => $extractionSuccess
        ]
    ]);

} catch (Exception $e) {
    error_log("Error en extract_content_hybrid_safe.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

function extractPDFContentHybrid($filePath) {
    $content = '';
    $method = '';
    $success = false;
    
    // Método 1: Extracción básica de metadatos y texto visible
    try {
        $fileContent = file_get_contents($filePath, false, null, 0, 32768); // Primeros 32KB
        
        // Verificar que es un PDF válido
        if (strpos($fileContent, '%PDF-') !== 0) {
            return [
                'content' => 'Archivo no es un PDF válido',
                'method' => 'invalid_pdf',
                'success' => false
            ];
        }
        
        // Extraer metadatos del PDF
        $metadata = extractPDFMetadata($fileContent);
        
        // Extraer texto visible (método básico)
        $visibleText = extractVisibleTextFromPDF($fileContent);
        
        if (!empty($visibleText)) {
            $content = "METADATOS DEL PDF:\n" . $metadata . "\n\n" . 
                      "CONTENIDO EXTRAÍDO:\n" . $visibleText;
            $method = 'php_basic_extraction';
            $success = true;
        } else {
            // Si no se puede extraer texto, al menos mostrar metadatos
            $content = "METADATOS DEL PDF:\n" . $metadata . "\n\n" .
                      "NOTA: No se pudo extraer texto visible del PDF. " .
                      "Para extracción completa se requiere pdftotext o librerías especializadas.";
            $method = 'metadata_only';
            $success = false;
        }
        
    } catch (Exception $e) {
        $content = "Error extrayendo contenido del PDF: " . $e->getMessage();
        $method = 'error';
        $success = false;
    }
    
    return [
        'content' => $content,
        'method' => $method,
        'success' => $success
    ];
}

function extractPDFMetadata($fileContent) {
    $metadata = [];
    
    // Extraer título
    if (preg_match('/\/Title\s*\(([^)]+)\)/', $fileContent, $matches)) {
        $metadata['title'] = trim($matches[1]);
    }
    
    // Extraer autor
    if (preg_match('/\/Author\s*\(([^)]+)\)/', $fileContent, $matches)) {
        $metadata['author'] = trim($matches[1]);
    }
    
    // Extraer sujeto
    if (preg_match('/\/Subject\s*\(([^)]+)\)/', $fileContent, $matches)) {
        $metadata['subject'] = trim($matches[1]);
    }
    
    // Extraer creador
    if (preg_match('/\/Creator\s*\(([^)]+)\)/', $fileContent, $matches)) {
        $metadata['creator'] = trim($matches[1]);
    }
    
    // Contar páginas (aproximado)
    $pageCount = preg_match_all('/\/Type\s*\/Page/', $fileContent);
    if ($pageCount > 0) {
        $metadata['pages'] = $pageCount;
    }
    
    // Formatear metadatos
    $formatted = "INFORMACIÓN DEL ARCHIVO:\n";
    foreach ($metadata as $key => $value) {
        $formatted .= "- " . ucfirst($key) . ": " . $value . "\n";
    }
    
    return $formatted;
}

function extractVisibleTextFromPDF($fileContent) {
    $text = '';
    
    // Buscar streams de texto en el PDF
    if (preg_match_all('/stream\s*(.*?)\s*endstream/s', $fileContent, $matches)) {
        foreach ($matches[1] as $stream) {
            // Decodificar texto básico (método simple)
            $decoded = '';
            $lines = explode("\n", $stream);
            
            foreach ($lines as $line) {
                // Buscar patrones de texto común en PDFs
                if (preg_match_all('/\(([^)]+)\)/', $line, $textMatches)) {
                    foreach ($textMatches[1] as $textMatch) {
                        $decoded .= $textMatch . ' ';
                    }
                }
            }
            
            if (!empty(trim($decoded))) {
                $text .= trim($decoded) . "\n";
            }
        }
    }
    
    // Limpiar y formatear el texto extraído
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    return $text;
}

function extractWordContentHybrid($filePath) {
    $content = '';
    $method = '';
    $success = false;
    
    try {
        // Para archivos .docx (ZIP-based)
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'docx') {
            $result = extractDocxContent($filePath);
            $content = $result['content'];
            $method = $result['method'];
            $success = $result['success'];
        } else {
            // Para archivos .doc (binario)
            $content = "Archivo .doc detectado. Para extracción completa se requiere catdoc o antiword.\n\n" .
                      "Archivo: " . basename($filePath) . "\n" .
                      "Tamaño: " . round(filesize($filePath) / 1024 / 1024, 2) . " MB";
            $method = 'unsupported_doc';
            $success = false;
        }
        
    } catch (Exception $e) {
        $content = "Error extrayendo contenido Word: " . $e->getMessage();
        $method = 'error';
        $success = false;
    }
    
    return [
        'content' => $content,
        'method' => $method,
        'success' => $success
    ];
}

function extractDocxContent($filePath) {
    // Los archivos .docx son archivos ZIP
    $zip = new ZipArchive();
    
    if ($zip->open($filePath) === TRUE) {
        // Leer el documento principal
        $documentXml = $zip->getFromName('word/document.xml');
        
        if ($documentXml) {
            // Extraer texto básico del XML
            $text = strip_tags($documentXml);
            $text = html_entity_decode($text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            if (!empty($text)) {
                return [
                    'content' => "CONTENIDO EXTRAÍDO DEL DOCX:\n\n" . $text,
                    'method' => 'zip_xml_extraction',
                    'success' => true
                ];
            }
        }
        
        $zip->close();
    }
    
    return [
        'content' => "No se pudo extraer contenido del archivo DOCX",
        'method' => 'zip_extraction_failed',
        'success' => false
    ];
}

function generateIntelligentSummary($content, $fileType) {
    $content = trim($content);
    
    if (empty($content)) {
        return 'Sin contenido extraído disponible';
    }
    
    // Para archivos PDF, extraer información más específica
    if ($fileType === 'pdf') {
        // Buscar patrones comunes en documentos de trading
        $patterns = [
            'patrones' => '/patrón|pattern/i',
            'velas' => '/vela|candle|doji|hammer/i',
            'soporte' => '/soporte|support/i',
            'resistencia' => '/resistencia|resistance/i',
            'análisis' => '/análisis|analysis/i',
            'trading' => '/trading|operación/i'
        ];
        
        $foundTopics = [];
        foreach ($patterns as $topic => $pattern) {
            if (preg_match($pattern, $content)) {
                $foundTopics[] = $topic;
            }
        }
        
        if (!empty($foundTopics)) {
            return "Documento sobre: " . implode(', ', $foundTopics) . ". " . 
                   substr($content, 0, 200) . "...";
        }
    }
    
    // Resumen general
    if (strlen($content) <= 300) {
        return $content;
    }
    
    // Buscar el primer párrafo completo
    $paragraphs = explode("\n\n", $content);
    $firstParagraph = trim($paragraphs[0]);
    
    if (strlen($firstParagraph) > 300) {
        $summary = substr($firstParagraph, 0, 300);
        $lastSpace = strrpos($summary, ' ');
        if ($lastSpace !== false) {
            $summary = substr($summary, 0, $lastSpace);
        }
        return $summary . '...';
    }
    
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
