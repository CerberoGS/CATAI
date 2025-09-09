<?php
// /bolsa/api/crypto.php
// Cifrado simétrico para API keys por usuario.
// AES-256-GCM con clave maestra Base64 (ENCRYPTION_KEY_BASE64) de config.php.
// Mantiene compatibilidad con tu implementación anterior (mismas firmas y retornos).

require_once __DIR__ . '/helpers.php'; // para cfg('ENCRYPTION_KEY_BASE64', ...)

/**
 * Obtiene la clave maestra binaria desde config.php (ENCRYPTION_KEY_BASE64).
 * Lanza excepción si no existe o no mide 32 bytes.
 */
function crypto_master_key(): string {
  $b64 = cfg('ENCRYPTION_KEY_BASE64', '');
  $raw = $b64 ? base64_decode($b64, true) : false;
  if ($raw === false || strlen($raw) !== 32) {
    throw new Exception('ENCRYPTION_KEY_BASE64 debe ser 32 bytes en Base64');
  }
  return $raw;
}

/**
 * Cifra texto plano y devuelve Base64 de (iv[12] || tag[16] || ciphertext).
 * Compat: si $plain está vacío, devuelve '' (no null).
 */
function encrypt_text($plain) {
  if (!$plain) return '';
  $key = crypto_master_key();

  $iv = random_bytes(12); // nonce recomendado para GCM
  $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false) {
    throw new Exception('openssl_encrypt falló');
  }
  if (strlen($tag) !== 16) {
    throw new Exception('Tag GCM inválido');
  }
  return base64_encode($iv . $tag . $cipher);
}

/**
 * Descifra Base64 generado por encrypt_text().
 * Compat: si $b64 está vacío/incorrecto, devuelve '' (no null).
 */
function decrypt_text($b64) {
  if (!$b64) return '';
  $buf = base64_decode($b64, true);
  if ($buf === false || strlen($buf) < 28) return '';

  $key = crypto_master_key();
  $iv  = substr($buf, 0, 12);
  $tag = substr($buf, 12, 16);
  $enc = substr($buf, 28);

  $plain = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  return $plain === false ? '' : $plain;
}
