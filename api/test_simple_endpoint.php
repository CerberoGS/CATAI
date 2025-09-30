<?php
declare(strict_types=1);

require_once 'config.php';
require_once 'db.php';
require_once 'helpers.php';

try {
    // Test simple
    json_out([
        'ok' => true,
        'message' => 'Endpoint de prueba funcionando',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} catch (Exception $e) {
    json_error("Error: " . $e->getMessage());
}
?>
