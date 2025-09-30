<?php
/**
 * Utilidades de cifrado con Sodium para CATIA
 * Basado en el código proporcionado, adaptado a PHP puro.
 * Keyring: config/keyring.json (proteger con .htaccess o chmod 600).
 * Requiere PHP 7.2+ con libsodium enabled.
 */

// Path al keyring (desde config.php)
function get_keyring_path(): string {
    global $CONFIG;
    return $CONFIG['KEYRING_PATH'] ?? '';
}

function catai_keyring(): array {
    static $KR = null;
    static $lastModified = null;
    
    if ($KR !== null) {
        // Verificar si el archivo cambió (solo en desarrollo)
        if (defined('APP_ENV') && APP_ENV === 'development') {
            $keyringPath = get_keyring_path();
            if (!empty($keyringPath) && file_exists($keyringPath)) {
                $currentModified = filemtime($keyringPath);
                if ($lastModified === null || $currentModified > $lastModified) {
                    $KR = null; // Forzar recarga
                }
            }
        }
        
        if ($KR !== null) return $KR;
    }
    
    $keyringPath = get_keyring_path();
    if (empty($keyringPath)) {
        throw new RuntimeException('KEYRING_PATH no configurado en config.php');
    }
    
    // Leer archivo con cache
    $json = @file_get_contents($keyringPath);
    if ($json === false) {
        throw new RuntimeException('Keyring no accesible: ' . $keyringPath);
    }
    
    $KR = json_decode($json, true);
    if (!$KR || !is_array($KR) || empty($KR['active_kid']) || empty($KR['keys'][$KR['active_kid']]['kek_b64'])) {
        throw new RuntimeException('Keyring inválido o sin active_kid configurado.');
    }
    
    // Verificar que la clave activa tenga status "active"
    $activeKid = $KR['active_kid'];
    if (empty($KR['keys'][$activeKid]['status']) || $KR['keys'][$activeKid]['status'] !== 'active') {
        throw new RuntimeException("Clave activa {$activeKid} no está en estado 'active'.");
    }
    
    // Cache del timestamp de modificación
    if (file_exists($keyringPath)) {
        $lastModified = filemtime($keyringPath);
    }
    
    return $KR;
}

function catai_active_kid(): string {
    return catai_keyring()['active_kid'];
}

function catai_get_kek(string $kid): string {
    $kr = catai_keyring();
    if (empty($kr['keys'][$kid]['kek_b64'])) {
        throw new RuntimeException("KEK no encontrada para kid={$kid}");
    }
    $kek = base64_decode($kr['keys'][$kid]['kek_b64'], true);
    if ($kek === false || strlen($kek) !== 32) {
        throw new RuntimeException("KEK inválida para kid={$kid} (debe ser 32 bytes).");
    }
    return $kek;
}

// HKDF-SHA256 para derivar Content Key (usa hash_hkdf de PHP 8+)
function catai_hkdf(string $ikm, string $salt, string $info, int $len = 32): string {
    if (!function_exists('hash_hkdf')) {
        // Polyfill simple si PHP <8 (implementar HMAC-based HKDF si needed)
        throw new RuntimeException('hash_hkdf no disponible; usa PHP 8+ o polyfill.');
    }
    return hash_hkdf('sha256', $ikm, $len, $info, $salt);
}

/**
 * Cifra (envelope) con v=1 (XChaCha20-Poly1305)
 * Devuelve JSON string para guardar en DB.
 */
function catai_encrypt(string $plaintext): string {
    $kid = catai_active_kid();
    $kek = catai_get_kek($kid);

    $salt = random_bytes(16);
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);  // 24 bytes

    $ckey = catai_hkdf($kek, $salt, 'catai-secrets-v1', 32);
    $aad = "catai|v1|{$kid}";

    $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $ckey);

    if ($ct === false) {
        throw new RuntimeException('Error en encriptación Sodium.');
    }

    $blob = [
        'v' => 1,
        'kid' => $kid,
        's' => base64_encode($salt),
        'n' => base64_encode($nonce),
        'ct' => base64_encode($ct),
    ];
    return json_encode($blob, JSON_UNESCAPED_SLASHES);
}

/**
 * Descifra soporte multi-versión (switch por v).
 * Si kid != active_kid, puedes re-cifrar y guardar para rotación "lazy".
 */
function catai_decrypt(string $blobJson): string {
    $j = json_decode($blobJson, true);
    if (!is_array($j) || !isset($j['v'])) {
        throw new RuntimeException('Blob de secreto inválido.');
    }

    switch ((int)$j['v']) {
        case 1:
            $kid = $j['kid'] ?? '';
            $kek = catai_get_kek($kid);
            $salt = base64_decode($j['s'] ?? '', true);
            $nonce = base64_decode($j['n'] ?? '', true);
            $ct = base64_decode($j['ct'] ?? '', true);
            
            if ($salt === false || $nonce === false || $ct === false || strlen($nonce) !== 24) {
                throw new RuntimeException('Campos base64 o nonce inválidos en blob v1.');
            }
            
            $ckey = catai_hkdf($kek, $salt, 'catai-secrets-v1', 32);
            $aad = "catai|v1|{$kid}";
            $pt = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $aad, $nonce, $ckey);
            if ($pt === false) {
                throw new RuntimeException('No se pudo descifrar el secreto (v1).');
            }
            return $pt;

        default:
            throw new RuntimeException('Versión de secreto no soportada: v=' . $j['v']);
    }
}

/**
 * Re-cifra si el blob no usa el active_kid (rotación "lazy").
 * Devuelve [plaintext, blobActualizado(bool), blobNuevo?].
 */
function catai_read_and_maybe_rewrap(string $blobJson): array {
    $pt = catai_decrypt($blobJson);

    $j = json_decode($blobJson, true);
    $wasKid = $j['kid'] ?? '';
    $active = catai_active_kid();

    if ($wasKid !== $active) {
        // Re-envelopa con la KEK activa
        $newBlob = catai_encrypt($pt);
        return [$pt, true, $newBlob];
    }
    return [$pt, false, null];
}

// Función helper para extraer últimos 4 chars (sin descifrar)
function catai_get_last_4_chars(string $plaintext): string {
    return substr($plaintext, -4);
}

// Ejemplo de uso (no ejecutar en producción sin keyring config)
// $encrypted = catai_encrypt('mi_api_key_secreta');
// $decrypted = catai_decrypt($encrypted);
// list($pt, $rewrapped, $newBlob) = catai_read_and_maybe_rewrap($encrypted);