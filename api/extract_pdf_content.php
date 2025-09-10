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

    // Ruta del archivo
    $filePath = "api/uploads/knowledge/{$user_id}/{$knowledge['stored_filename']}";
    
    if (!file_exists($filePath)) {
        json_error('Archivo físico no encontrado', 404);
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
        'file_type' => $knowledge['file_type'],
        'file_size' => $knowledge['file_size']
    ]);

} catch (Exception $e) {
    error_log("Error en extract_pdf_content.php: " . $e->getMessage());
    json_error('Error interno del servidor: ' . $e->getMessage(), 500);
}

function extractPDFContent($filePath) {
    // Para PDFs, usar una librería simple o comando del sistema
    // Por ahora, retornar un mensaje indicando que se necesita implementar
    return "Contenido PDF extraído de: " . basename($filePath) . "\n\n" .
           "Nota: La extracción completa de PDF requiere librerías adicionales como pdftotext o TCPDF.\n" .
           "Para implementación completa, se recomienda:\n" .
           "1. Instalar pdftotext en el servidor\n" .
           "2. Usar comando: pdftotext " . $filePath . " -\n" .
           "3. O implementar TCPDF/Poppler para extracción avanzada";
}

function extractWordContent($filePath) {
    // Para documentos Word, usar comando del sistema o librería
    return "Contenido Word extraído de: " . basename($filePath) . "\n\n" .
           "Nota: La extracción completa de Word requiere librerías adicionales.\n" .
           "Para implementación completa, se recomienda:\n" .
           "1. Usar comando: catdoc " . $filePath . "\n" .
           "2. O implementar PHPWord para extracción avanzada";
}

function generateSummary($content) {
    // Generar resumen básico (primeros 200 caracteres)
    $summary = trim($content);
    if (strlen($summary) > 200) {
        $summary = substr($summary, 0, 200) . '...';
    }
    
    if (empty($summary)) {
        return 'Sin contenido extraído disponible';
    }
    
    return $summary;
}
