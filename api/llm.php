<?php
// /bolsa/api/llm_analyze.php
// Proxy que incluye el archivo real del analizador IA, sin pelearse por el nombre.

header('Content-Type: application/json; charset=utf-8');

$alt1 = __DIR__ . '/ai_analyze.php'; // nombre "correcto"
$alt2 = __DIR__ . '/ia_analyze.php'; // el que tienes actualmente

if (file_exists($alt1)) { require $alt1; exit; }
if (file_exists($alt2)) { require $alt2; exit; }

// Si ninguno existe, responder claro (evitar HTML/0 bytes).
http_response_code(500);
echo json_encode([
  'error'  => 'ai_script_missing',
  'detail' => 'No se encontró ni ai_analyze.php ni ia_analyze.php en /bolsa/api/'
]);
