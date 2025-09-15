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
    
    $results = [
        'ok' => true,
        'message' => 'Extracción simulada (sin llamadas a OpenAI)',
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
    
    // Paso 2: Simular subida a OpenAI
    $results['processing'][] = [
        'step' => 2,
        'name' => 'Subir archivo a OpenAI',
        'status' => 'processing',
        'message' => 'Simulando subida a OpenAI...'
    ];
    
    // Simular tiempo de subida
    sleep(2);
    
    $results['processing'][] = [
        'step' => 2,
        'name' => 'Subir archivo a OpenAI',
        'status' => 'success',
        'message' => 'Archivo subido exitosamente (simulado)',
        'openai_file_id' => 'file-simulated-123',
        'note' => 'Simulado - no se hizo llamada real a OpenAI'
    ];
    
    // Paso 3: Simular análisis con IA
    $results['processing'][] = [
        'step' => 3,
        'name' => 'Análisis con IA',
        'status' => 'processing',
        'message' => 'Simulando análisis con IA...'
    ];
    
    // Simular tiempo de análisis
    sleep(3);
    
    // Generar contenido simulado basado en el nombre del archivo
    $filename = $file['original_filename'];
    $simulatedContent = generateSimulatedContent($filename);
    
    $results['processing'][] = [
        'step' => 3,
        'name' => 'Análisis con IA',
        'status' => 'success',
        'message' => 'Análisis completado exitosamente (simulado)',
        'content_extracted' => $simulatedContent['content'],
        'summary' => $simulatedContent['summary'],
        'note' => 'Simulado - no se hizo llamada real a OpenAI'
    ];
    
    // Paso 4: Simular guardado en base de datos
    $results['processing'][] = [
        'step' => 4,
        'name' => 'Guardar en base de datos',
        'status' => 'processing',
        'message' => 'Simulando guardado...'
    ];
    
    // Simular tiempo de guardado
    sleep(1);
    
    $results['processing'][] = [
        'step' => 4,
        'name' => 'Guardar en base de datos',
        'status' => 'success',
        'message' => 'Resultados guardados exitosamente (simulado)',
        'note' => 'Simulado - no se guardó en la base de datos'
    ];
    
    $results['summary'] = [
        'total_steps' => 4,
        'completed_steps' => 4,
        'total_time' => '6 segundos (simulado)',
        'api_method' => 'Simulado (sin llamadas a OpenAI)',
        'note' => 'Cuenta de OpenAI sin créditos - usando simulación',
        'recommendation' => 'Recargar créditos en OpenAI para funcionalidad real'
    ];
    
    ok($results);
    
} catch (Exception $e) {
    error_log("ERROR en ai_extract_content_simulated_safe.php: " . $e->getMessage());
    json_error('Error interno: ' . $e->getMessage());
}

// Función para generar contenido simulado
function generateSimulatedContent($filename) {
    $content = '';
    $summary = '';
    
    if (strpos(strtolower($filename), 'patrones') !== false) {
        $content = "ANÁLISIS DE PATRONES DE VELAS JAPONESAS

RESUMEN EJECUTIVO:
Los patrones de velas japonesas son formaciones gráficas que indican posibles reversiones o continuaciones de tendencia en el mercado. Este documento analiza los patrones más importantes para el trading.

CONCEPTOS CLAVE:
- Doji: Indica indecisión del mercado
- Hammer: Señal de reversión alcista
- Shooting Star: Señal de reversión bajista
- Engulfing: Patrón de reversión fuerte
- Harami: Patrón de consolidación
- Morning Star: Reversión alcista en tres velas
- Evening Star: Reversión bajista en tres velas

ESTRATEGIAS DE TRADING:
- Usar patrones de reversión en soportes y resistencias
- Confirmar con volumen y otros indicadores
- Establecer stop loss por debajo del patrón
- Tomar ganancias en niveles de resistencia

GESTIÓN DE RIESGO:
- No confiar solo en patrones de velas
- Usar stop loss obligatorio
- Limitar riesgo al 2% por operación

RECOMENDACIONES:
- Combinar con análisis técnico tradicional
- Practicar en cuenta demo antes de usar dinero real
- Mantener un diario de trading para mejorar";

        $summary = "Documento sobre patrones de velas japonesas con estrategias de trading y gestión de riesgo.";
    } else {
        $content = "ANÁLISIS DE DOCUMENTO DE TRADING

RESUMEN EJECUTIVO:
Este documento contiene información valiosa sobre estrategias de trading y análisis de mercado.

CONCEPTOS CLAVE:
- Análisis técnico y fundamental
- Gestión de riesgo
- Psicología del trading
- Estrategias de entrada y salida
- Análisis de tendencias

ESTRATEGIAS DE TRADING:
- Identificar tendencias principales
- Usar indicadores técnicos
- Establecer niveles de entrada y salida
- Gestionar el riesgo adecuadamente

GESTIÓN DE RIESGO:
- Usar stop loss
- Diversificar posiciones
- Limitar el riesgo por operación
- Mantener disciplina emocional

RECOMENDACIONES:
- Estudiar continuamente
- Practicar en cuenta demo
- Mantener un diario de trading
- Buscar mentoría de traders experimentados";

        $summary = "Documento de trading con estrategias y conceptos fundamentales.";
    }
    
    return [
        'content' => $content,
        'summary' => $summary
    ];
}
?>
