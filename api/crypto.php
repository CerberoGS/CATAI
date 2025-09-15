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
  // Normalizar: quitar espacios/saltos y completar padding antes de decodificar
  $b64 = (string) cfg('ENCRYPTION_KEY_BASE64', '');
  // Eliminar cualquier carácter fuera del alfabeto Base64 (incluye comillas tipográficas)
  $b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $b64 ?? '');
  // Quitar espacios/saltos residuales
  $b64 = str_replace(["\r","\n"," "], '', $b64);
  if ($b64 !== '') {
    $rem = strlen($b64) % 4;
    if ($rem) $b64 .= str_repeat('=', 4 - $rem);
  }
  $raw = $b64 !== '' ? base64_decode($b64, true) : false;
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

// Nueva: loader de llaves (/.secret/keys.json)
function load_keys(): array {
    $path = __DIR__ . '/../.secret/keys.json';
    $json = json_decode(file_get_contents($path), true);
    if (!$json || empty($json['active_kid']) || empty($json['keys'][$json['active_kid']])) {
        throw new RuntimeException('Key registry invalid');
    }
    // decodifica base64: devuelve ['active_kid'=>..., 'keys'=>['kid'=>rawKeyBytes]]
    foreach ($json['keys'] as $kid => $b64) {
        if (str_starts_with($b64, 'base64:')) $b64 = substr($b64, 7);
        $json['keys'][$kid] = base64_decode($b64, true);
    }
    return $json;
}

function hkdf(string $ikm, string $salt, string $info, int $len = 32): string {
    return hash_hkdf('sha256', $ikm, $len, $info, $salt);
}

// ========== cifrar ==========
function secret_encrypt(string $plaintext, ?string $kid = null): string {
    $reg = load_keys();
    $kid = $kid ?: $reg['active_kid'];
    $kek = $reg['keys'][$kid] ?? null;
    if (!$kek || strlen($kek) < 32) throw new RuntimeException('KEK not found');

    $salt  = random_bytes(16);
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

    $contentKey = hkdf($kek, $salt, 'catai-secrets-v1', 32);
    $ad = "v1|{$kid}"; // asociated data para autenticar cabecera

    $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $ad, $nonce, $contentKey);

    // Empaquetar como base64 (simple y portable)
    $blob = json_encode([
        'v' => 1,
        'kid' => $kid,
        's' => base64_encode($salt),
        'n' => base64_encode($nonce),
        'ct' => base64_encode($ct),
    ], JSON_UNESCAPED_SLASHES);

    return $blob; // guarda en VARBINARY/TEXT
}

// ========== descifrar (con rotación opcional) ==========
function secret_decrypt(string $blob): array {
    // retorno: ['plain'=>..., 'kid'=>..., 'needs_rewrap'=>bool]
    $j = json_decode($blob, true);
    if (!$j || ($j['v'] ?? null) !== 1) {
        // LEGACY: intenta con llaves viejas (solo para migración)
        throw new RuntimeException('Legacy/unknown secret format');
    }
    $kid   = $j['kid'];
    $salt  = base64_decode($j['s']);
    $nonce = base64_decode($j['n']);
    $ct    = base64_decode($j['ct']);

    $reg = load_keys();
    $kek = $reg['keys'][$kid] ?? null;
    if (!$kek) throw new RuntimeException('KEK not available for kid='.$kid);

    $contentKey = hkdf($kek, $salt, 'catai-secrets-v1', 32);
    $ad = "v1|{$kid}";
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $ad, $nonce, $contentKey);
    if ($plain === false) throw new RuntimeException('Decryption failed');

    $needs = ($kid !== $reg['active_kid']); // rotar si cambió la activa
    return ['plain' => $plain, 'kid' => $kid, 'needs_rewrap' => $needs];
}

// ========== re-cifrar si cambió la KID ==========
function secret_maybe_rewrap(PDO $pdo, string $table, string $col, string $pkCol, $id, string $blob): string {
    $res = secret_decrypt($blob);
    if (!$res['needs_rewrap']) return $blob;

    $newBlob = secret_encrypt($res['plain']); // usa active_kid
    $stmt = $pdo->prepare("UPDATE {$table} SET {$col}=? WHERE {$pkCol}=? LIMIT 1");
    $stmt->execute([$newBlob, $id]);
    return $newBlob;
}
