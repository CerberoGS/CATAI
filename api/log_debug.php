<?php
// log_debug.php — endpoint mínimo para registrar eventos de depuración enviados desde el frontend.
// Es tolerante a errores y opcionalmente anota el usuario si trae Bearer token válido.

declare(strict_types=1);

require_once __DIR__ . '/_safe_wrapper.php'; // JSON + manejo de fatales
require_once __DIR__ . '/helpers.php';       // CORS + utilidades

// Solo POST (permite OPTIONS por preflight)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  json_out(['error' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input') ?: '';
if ($raw === '') {
  json_out(['error' => 'no-data'], 400);
}

// Intentar parsear JSON; si no es JSON, registrar como texto plano
$parsed = json_decode($raw, true);
$payload = is_array($parsed) ? $parsed : ['text' => $raw];

// Datos básicos del request
$entry = [
  'ts'   => date('c'),
  'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
  'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
  'uri'  => $_SERVER['REQUEST_URI'] ?? '',
  'data' => $payload,
];

// Si hay un Bearer token válido, anotar email/id
$cfg = $GLOBALS['__APP_CONFIG'] ?? [];
$secret = (string)($cfg['JWT_SECRET'] ?? '');
$tok = bearer_token();
if ($secret !== '' && $tok) {
  [$ok, $res] = jwt_verify_hs256($tok, $secret);
  if ($ok && is_array($res)) {
    if (isset($res['email'])) $entry['user'] = (string)$res['email'];
    if (isset($res['id']))    $entry['user_id'] = (int)$res['id'];
  }
}

// Asegurar directorio y escribir
$dir = __DIR__ . '/logs';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$logFile = $dir . '/debug.log';
if (function_exists('rotate_log')) { @rotate_log($logFile, 524288, 3); }
@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

json_out(['ok' => true]);
