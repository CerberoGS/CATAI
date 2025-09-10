<?php
// /catai/api/crypto_diag_safe.php
// Diagnóstico seguro del entorno de cifrado (no expone secretos).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . cfg('ALLOWED_ORIGIN', '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  // Requiere usuario autenticado
  $user = require_user();

  $b64 = (string) cfg('ENCRYPTION_KEY_BASE64', '');
  $dec = $b64 !== '' ? base64_decode($b64, true) : false;
  $decodedLen = ($dec === false) ? 0 : strlen((string)$dec);
  // Preview enmascarado de la clave (no exponer completa)
  $clean = trim(str_replace(["\r","\n"," "], '', (string)$b64));
  $b64Preview = ($clean === '') ? null : (substr($clean, 0, 6) . '…' . substr($clean, -6));

  // Probar ciclo encrypt/decrypt sin exponer valores
  $encOk = null; $decOk = null;
  try {
    $testPlain = 'diag_' . substr(hash('sha256', (string)microtime(true)), 0, 16);
    $blob = encrypt_text($testPlain);           // Base64
    $back = decrypt_text($blob);                // Texto
    $encOk = is_string($blob) && $blob !== '';
    $decOk = is_string($back) && ($back === $testPlain);
  } catch (Throwable $e) {
    $encOk = false; $decOk = false;
  }

  $out = [
    'ok' => true,
    'b64_len' => strlen($b64),
    'decoded_len' => $decodedLen,
    'is_32_bytes' => ($decodedLen === 32),
    'b64_preview' => $b64Preview,
    'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? constant('OPENSSL_VERSION_TEXT') : null,
    'encrypt_ok' => $encOk,
    'decrypt_ok' => $decOk,
  ];

  json_out($out);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}


