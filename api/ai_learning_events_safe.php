<?php
declare(strict_types=1);

require_once 'helpers.php';
require_once 'db.php';

// Aplicar CORS
apply_cors();

try {
    $user = require_user();
    $user_id = $user['user_id'] ?? $user['id'] ?? null;
    
    if (!$user_id) {
        json_error('Usuario no válido', 400);
    }

    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Crear nuevo evento de aprendizaje
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            json_error('Datos JSON inválidos', 400);
        }

        $event_type = $input['event_type'] ?? '';
        $event_data = $input['event_data'] ?? [];
        $confidence_impact = (float)($input['confidence_impact'] ?? 0.0);

        if (empty($event_type)) {
            json_error('Tipo de evento es requerido', 400);
        }

        // Guardar evento
        $stmt = $pdo->prepare("
            INSERT INTO ai_learning_events 
            (user_id, event_type, event_data, confidence_impact, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $event_data_json = json_encode($event_data);
        
        $stmt->execute([
            $user_id,
            $event_type,
            $event_data_json,
            $confidence_impact
        ]);

        $event_id = $pdo->lastInsertId();

        // Actualizar métricas de aprendizaje si es necesario
        if ($event_type === 'analysis_outcome') {
            $outcome = $event_data['outcome'] ?? null;
            $traded = $event_data['traded'] ?? false;
            
            if ($outcome) {
                $success = ($outcome === 'positive' || $outcome === 'pos');
                
                // Actualizar métricas
                $stmt = $pdo->prepare("
                    UPDATE ai_learning_metrics 
                    SET 
                        total_analyses = total_analyses + 1,
                        successful_analyses = successful_analyses + ?,
                        success_rate = (successful_analyses + ?) / (total_analyses + 1) * 100,
                        last_analysis_date = NOW(),
                        updated_at = NOW()
                    WHERE user_id = ?
                ");
                
                $stmt->execute([$success ? 1 : 0, $success ? 1 : 0, $user_id]);
            }
        }

        json_out([
            'ok' => true,
            'event_id' => $event_id,
            'message' => 'Evento de aprendizaje creado'
        ]);

    } else {
        // Obtener eventos de aprendizaje
        $limit = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        $event_type = $_GET['event_type'] ?? null;

        // Construir consulta
        $where_conditions = ['user_id = ?'];
        $params = [$user_id];

        if ($event_type) {
            $where_conditions[] = 'event_type = ?';
            $params[] = $event_type;
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Obtener eventos
        $stmt = $pdo->prepare("
            SELECT 
                id,
                event_type,
                event_data,
                confidence_impact,
                created_at
            FROM ai_learning_events 
            WHERE {$where_clause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Obtener total de eventos
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM ai_learning_events 
            WHERE {$where_clause}
        ");
        
        $stmt->execute(array_slice($params, 0, -2));
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Procesar eventos para incluir datos parseados
        foreach ($events as &$event) {
            if ($event['event_data']) {
                $event['event_data'] = json_decode($event['event_data'], true);
            }
        }

        json_out([
            'ok' => true,
            'events' => $events,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

} catch (Exception $e) {
    error_log("Error en ai_learning_events_safe.php: " . $e->getMessage());
    json_error('Error procesando eventos de aprendizaje', 500);
}
