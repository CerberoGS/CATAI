<?php declare(strict_types=1);

/**
 * /bolsa/api/helpers.php
 * Utilidades comunes para endpoints JSON.
 * - Salidas JSON consistentes (json_out, json_error)
 * - Lectura de body JSON (json_input / read_json_body)
 * - CORS básico
 * - Extracción de Bearer token
 * - Verificación JWT HS256 (require_user)
 * - Helpers HTTP (GET/POST con timeout)
 * - Normalización de email
 * - Gestión de API keys por usuario
 *
 * NOTA: Requiere /bolsa/api/config.php para JWT_SECRET y otros.
 */

header_remove('X-Powered-By');

if (!defined('JSON_FLAGS')) {
  define('JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ------------------------------ Log rotation ------------------------------ */
if (!function_exists('rotate_log')) {
  /**
   * Simple size-based log rotation: keeps current file under maxBytes by
   * rotating to .1, .2, ... up to $backups copies when size exceeds limit.
   */
  function rotate_log(string $path, int $maxBytes = 524288, int $backups = 3): void {
    if ($maxBytes <= 0 || $backups <= 0) return;
    clearstatcache(true, $path);
    if (@filesize($path) === false) return; // nothing to rotate
    $size = (int)@filesize($path);
    if ($size < $maxBytes) return;
    $dir = dirname($path);
    if (!is_dir($dir)) return;
    // Shift old files
    for ($i = $backups - 1; $i >= 1; $i--) {
      $src = $path . '.' . $i;
      $dst = $path . '.' . ($i + 1);
      if (is_file($src)) @rename($src, $dst);
    }
    // Move current to .1 and truncate
    @rename($path, $path . '.1');
    @file_put_contents($path, '');
  }
}

/* ----------------------------- Carga de config ---------------------------- */
if (!isset($GLOBALS['__APP_CONFIG'])) {
  $GLOBALS['__APP_CONFIG'] = require __DIR__ . '/config.php';
}
$config = $GLOBALS['__APP_CONFIG'] ?? [];

/* --------------------------------- CORS ---------------------------------- */
if (!function_exists('apply_cors')) {
  function apply_cors(?array $cfg = null): void {
    $cfg = $cfg ?? [];

    // Config soportada:
    // - ALLOWED_ORIGINS: array de orígenes permitidos
    // - ALLOWED_ORIGIN:  string único permitido (retro-compat)
    // - CORS_ORIGINS / CORS_ORIGIN: alias legacy
    // - CORS_ALLOW_CREDENTIALS: bool (por defecto true)

    $allowCreds = isset($cfg['CORS_ALLOW_CREDENTIALS']) ? (bool)$cfg['CORS_ALLOW_CREDENTIALS'] : true;

    $origins = [];
    if (!empty($cfg['ALLOWED_ORIGINS']) && is_array($cfg['ALLOWED_ORIGINS'])) {
      $origins = array_values(array_filter(array_map('strval', $cfg['ALLOWED_ORIGINS'])));
    } elseif (!empty($cfg['ALLOWED_ORIGIN']) && is_string($cfg['ALLOWED_ORIGIN'])) {
      $origins = [ (string)$cfg['ALLOWED_ORIGIN'] ];
    } elseif (!empty($cfg['CORS_ORIGINS']) && is_array($cfg['CORS_ORIGINS'])) {
      $origins = array_values(array_filter(array_map('strval', $cfg['CORS_ORIGINS'])));
    } elseif (!empty($cfg['CORS_ORIGIN']) && is_string($cfg['CORS_ORIGIN'])) {
      $origins = [ (string)$cfg['CORS_ORIGIN'] ];
    }

    // Origin de la petición
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;

    // Determinar qué origin devolver
    $originToAllow = null;
    if ($reqOrigin && $origins) {
      // Comparación exacta
      if (in_array($reqOrigin, $origins, true)) {
        $originToAllow = $reqOrigin;
      }
    } elseif ($reqOrigin && !$origins) {
      // Si no hay lista configurada, por compat: permitir mismo origin solo si creds desactivadas
      if (!$allowCreds) $originToAllow = '*';
      else $originToAllow = $reqOrigin; // reflejar solo si permitimos credenciales y confiamos en el único dominio que sirve la app
    } else {
      // No hay header Origin (p.ej. cURL o misma-origin); no es necesario enviar A-C-A-Origin
      $originToAllow = null;
    }

    $allowHeaders = 'Authorization, Content-Type, X-Requested-With';
    $allowMethods = 'GET, POST, OPTIONS';

    if (!headers_sent()) {
      if ($originToAllow) {
        header('Vary: Origin');
        // Con credenciales activas, no se permite '*', se debe reflejar el Origin
        if ($allowCreds && $originToAllow === '*') {
          // No enviar CORS permisivo con credenciales; forzar ausencia para seguridad
        } else {
          header('Access-Control-Allow-Origin: ' . $originToAllow);
        }
      }
      if ($allowCreds) header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Headers: ' . ($
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? $allowHeaders
      ));
      header('Access-Control-Allow-Methods: ' . ($
        $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? $allowMethods
      ));
    }

    // Preflight
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
      // Si hay Origin y no está permitido explícitamente, responder 403
      if ($reqOrigin && $origins && !in_array($reqOrigin, $origins, true)) {
        http_response_code(403);
        exit;
      }
      http_response_code(204);
      exit;
    }
  }
}
apply_cors($config);

/* ---------------------------- JSON I/O helpers --------------------------- */
if (!function_exists('json_header')) {
  function json_header(int $status = 200): void {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
  }
}

if (!function_exists('json_out')) {
  function json_out($data, int $status = 200): void {
    json_header($status);
    echo json_encode($data, JSON_FLAGS);
    exit;
  }
}

if (!function_exists('json_error')) {
  function json_error(string $message, int $status = 400, $detail = null): void {
    $payload = ['error' => $message];
    if ($detail !== null) $payload['detail'] = $detail;
    json_out($payload, $status);
  }
}

if (!function_exists('json_input')) {
  function json_input(bool $assoc = true) {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return $assoc ? [] : (object)[];
    $data = json_decode($raw, $assoc);
    if (json_last_error() !== JSON_ERROR_NONE) {
      json_error('invalid-json', 400, json_last_error_msg());
    }
    return $data;
  }
}

/* ----------------------------- Header helpers ---------------------------- */
if (!function_exists('getallheaders')) { // polyfill para CGI/FastCGI
  function getallheaders(): array {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (strpos($name, 'HTTP_') === 0) {
        $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
        $headers[$key] = $value;
      }
    }
    return $headers;
  }
}

if (!function_exists('request_header')) {
  function request_header(string $name): ?string {
    $h = getallheaders();
    return $h[$name] ?? $h[strtolower($name)] ?? $h[strtoupper($name)] ?? null;
  }
}

if (!function_exists('bearer_token')) {
  function bearer_token(): ?string {
    $h = request_header('Authorization') ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    if (isset($_GET['token']) && is_string($_GET['token'])) return trim($_GET['token']);
    return null;
  }
}

/* ------------------------------ JWT helpers ------------------------------ */
if (!function_exists('b64url_decode')) {
  function b64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
  }
}
if (!function_exists('b64url_encode')) {
  function b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }
}

if (!function_exists('jwt_verify_hs256')) {
  function jwt_verify_hs256(string $jwt, string $secret): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return [false, 'malformed'];
    [$h64, $p64, $s64] = $parts;
    $header  = json_decode(b64url_decode($h64), true);
    $payload = json_decode(b64url_decode($p64), true);
    if (!is_array($header) || !is_array($payload)) return [false, 'decode-error'];
    if (($header['alg'] ?? '') !== 'HS256') return [false, 'alg-unsupported'];

    $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
    $sig = b64url_decode($s64);
    if (!hash_equals($expected, $sig)) return [false, 'bad-signature'];

    if (isset($payload['exp']) && time() >= (int)$payload['exp']) return [false, 'token-expired'];
    return [true, $payload];
  }
}

if (!function_exists('require_user')) {
  function require_user(): array {
    $cfg = $GLOBALS['__APP_CONFIG'] ?? [];
    $secret = (string)($cfg['JWT_SECRET'] ?? '');
    if ($secret === '') json_error('server-misconfigured', 500, 'JWT secret missing');

    $tok = bearer_token();
    if (!$tok) json_error('missing-token', 401, 'Authorization: Bearer <token>');

    [$ok, $res] = jwt_verify_hs256($tok, $secret);
    if (!$ok) {
      $reason = is_string($res) ? $res : 'invalid-token';
      json_error('invalid-token', 401, $reason);
    }
    if (!isset($res['email']) && !isset($res['sub'])) {
      json_error('invalid-token', 401, 'payload-missing');
    }
    return $res;
  }
}

/* ------------------------------- HTTP fetch ------------------------------ */
if (!function_exists('http_user_agent')) {
  function http_user_agent(): string {
    return 'BolsaAPI/1.0 (+https://cerberogrowthsolutions.com)';
  }
}

if (!function_exists('http_get')) {
  function http_get(string $url, array $headers = [], int $timeout = 12): string {
    $ch = curl_init($url);
    $hdrs = [];
    foreach ($headers as $k => $v) $hdrs[] = $k . ': ' . $v;
    $hdrs[] = 'User-Agent: ' . http_user_agent();

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER    => true,
      CURLOPT_FOLLOWLOCATION    => true,
      CURLOPT_CONNECTTIMEOUT    => $timeout,
      CURLOPT_TIMEOUT           => $timeout,
      CURLOPT_HTTPHEADER        => $hdrs,
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); json_error('upstream-error', 502, $err); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code >= 400) json_error('upstream-http-' . $code, 502);
    return (string)$body;
  }
}

if (!function_exists('http_get_json')) {
  function http_get_json(string $url, array $headers = [], int $timeout = 12): array {
    $txt = http_get($url, array_merge(['Accept' => 'application/json'], $headers), $timeout);
    $j = json_decode($txt, true);
    if (!is_array($j)) json_error('upstream-non-json', 502, substr($txt, 0, 200));
    return $j;
  }
}

if (!function_exists('http_post_json')) {
  function http_post_json(string $url, $payload, array $headers = [], int $timeout = 12): array {
    $ch = curl_init($url);
    $json = is_string($payload) ? $payload : json_encode($payload, JSON_FLAGS);
    $hdrs = [];
    foreach ($headers as $k => $v) $hdrs[] = $k . ': ' . $v;
    $hdrs[] = 'Content-Type: application/json';
    $hdrs[] = 'User-Agent: ' . http_user_agent();

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER    => true,
      CURLOPT_FOLLOWLOCATION    => true,
      CURLOPT_CONNECTTIMEOUT    => $timeout,
      CURLOPT_TIMEOUT           => $timeout,
      CURLOPT_HTTPHEADER        => $hdrs,
      CURLOPT_POST              => true,
      CURLOPT_POSTFIELDS        => $json,
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); json_error('upstream-error', 502, $err); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code >= 400) json_error('upstream-http-' . $code, 502);
    $resp = json_decode((string)$body, true);
    if (!is_array($resp)) json_error('upstream-non-json', 502, substr((string)$body, 0, 200));
    return $resp;
  }
}

/* --------------------------- Utilidades varias --------------------------- */
if (!function_exists('normalize_email')) {
  function normalize_email(?string $email): string {
    $email = trim((string)$email);
    $email = strtolower($email);
    return $email;
  }
}

if (!function_exists('ok')) {
  function ok($data = null): void {
    json_out(['ok' => true, 'data' => $data]);
  }
}

if (!function_exists('query_url')) {
  function query_url(string $base, array $params): string {
    $qs = http_build_query($params);
    if ($qs === '') return $base;
    return $base . (strpos($base, '?') === false ? '?' : '&') . $qs;
  }
}

/* ------------------------- Compat para endpoints viejos ------------------ */
if (!function_exists('read_json_body')) {
  function read_json_body() { return json_input(true); }
}

/* ======================================================================
   CLAVES POR USUARIO
   ====================================================================== */

if (!function_exists('set_api_key_for')) {
  function set_api_key_for(int $userId, string $provider, string $confKey, string $apiKey): void {
    $provider = strtolower(trim($provider));
    if (!preg_match('/^[a-z0-9_-]{2,32}$/', $provider)) { throw new InvalidArgumentException('invalid-provider'); }
    // Log temporal para depuración
    $logFile = __DIR__ . '/logs/safe.log';
    $msg = date('Y-m-d H:i:s') . " set_api_key_for: userId=$userId provider='$provider' apiKey='" . substr($apiKey,0,8) . "...'\n";
    file_put_contents($logFile, $msg, FILE_APPEND);
    if (!function_exists('db')) require_once __DIR__ . '/db.php';
    $pdo = db();
    $sql = "INSERT INTO user_api_keys (user_id, provider, api_key_enc, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE api_key_enc = VALUES(api_key_enc), updated_at = NOW()";
    $st = $pdo->prepare($sql);
    $st->execute([$userId, $provider, $apiKey]);
  }
}

if (!function_exists('delete_api_key_for')) {
  function delete_api_key_for(int $userId, string $provider): void {
    $provider = strtolower(trim($provider));
    if (!preg_match('/^[a-z0-9_-]{2,32}$/', $provider)) { return; }
    if (!function_exists('db')) require_once __DIR__ . '/db.php';
    $pdo = db();
    $st = $pdo->prepare("DELETE FROM user_api_keys WHERE user_id = ? AND provider = ?");
    $st->execute([$userId, $provider]);
  }
}

if (!function_exists('get_api_key_for')) {
  function get_api_key_for(int $userId, string $provider, ?string $fallbackConfKey = null): string {
    $provider = strtolower(trim($provider));
    $key = '';
    try {
      if (!function_exists('db')) require_once __DIR__ . '/db.php';
      $pdo = db();
      $st = $pdo->prepare("SELECT api_key_enc FROM user_api_keys WHERE user_id = ? AND provider = ? ORDER BY id DESC LIMIT 1");
      $st->execute([$userId, $provider]);
      $row = $st->fetch();
      if ($row && is_string($row['api_key_enc']) && trim((string)$row['api_key_enc']) !== '') {
        $key = trim((string)$row['api_key_enc']);
      }
    } catch (\Throwable $e) {
      // Si falla la DB, seguimos con fallback
    }

    if ($key !== '') return $key;

    $cfg     = $GLOBALS['__APP_CONFIG'] ?? [];
    $confKey = $fallbackConfKey ?: strtoupper($provider) . '_API_KEY';

    $env = getenv($confKey);
    if (is_string($env) && trim($env) !== '') return trim($env);

    if (isset($cfg[$confKey]) && is_string($cfg[$confKey]) && trim($cfg[$confKey]) !== '') {
      return trim($cfg[$confKey]);
    }

    if (isset($cfg['providers'][$provider]['api_key']) && is_string($cfg['providers'][$provider]['api_key'])) {
      $v = trim($cfg['providers'][$provider]['api_key']);
      if ($v !== '') return $v;
    }

    return '';
  }
}

/* ---------------- Preferencias de red por proveedor (IA) ----------------- */
if (!function_exists('net_for_provider')) {
  function net_for_provider(int $userId, string $provider): array {
    $timeout = 8000; // ms
    $retries = 0;
    $cfg = $GLOBALS['__APP_CONFIG'] ?? [];
    if (isset($cfg['NET']['ia_timeout_ms'])) $timeout = (int)$cfg['NET']['ia_timeout_ms'];
    if (isset($cfg['NET']['ia_retries']))    $retries = (int)$cfg['NET']['ia_retries'];
    return ['timeout_ms' => $timeout, 'retries' => $retries];
  }
}

/* -------------------------- User settings (DB) --------------------------- */
if (!function_exists('user_settings_extras')) {
  /**
   * Lee la columna data (JSON) de user_settings para un usuario y la retorna como array.
   * Devuelve [] si no hay datos o en caso de error.
   */
  function user_settings_extras(int $userId): array {
    try {
      if (!function_exists('db')) require_once __DIR__ . '/db.php';
      $pdo = db();
      $st = $pdo->prepare("SELECT data FROM user_settings WHERE user_id = ? LIMIT 1");
      $st->execute([$userId]);
      $row = $st->fetch();
      if ($row && isset($row['data']) && is_string($row['data']) && $row['data'] !== '') {
        $tmp = json_decode((string)$row['data'], true);
        return is_array($tmp) ? $tmp : [];
      }
    } catch (\Throwable $e) { /* ignore */ }
    return [];
  }
}

if (!function_exists('user_net_timeout_ms')) {
  /**
   * Devuelve timeout_ms guardado en settings.data.net[$provider].timeout_ms o $fallbackMs.
   */
  function user_net_timeout_ms(int $userId, string $provider, int $fallbackMs = 25000): int {
    $provider = strtolower(trim($provider));
    $extras = user_settings_extras($userId);
    if (isset($extras['net']) && is_array($extras['net'])) {
      $net = $extras['net'];
      if (isset($net[$provider]) && is_array($net[$provider])) {
        $ms = (int)($net[$provider]['timeout_ms'] ?? 0);
        if ($ms > 0) return $ms;
      }
    }
    return $fallbackMs;
  }
}

if (!function_exists('user_net_retries')) {
  /**
   * Devuelve retries guardado en settings.data.net[$provider].retries o $fallback.
   */
  function user_net_retries(int $userId, string $provider, int $fallback = 0): int {
    $provider = strtolower(trim($provider));
    $extras = user_settings_extras($userId);
    if (isset($extras['net']) && is_array($extras['net'])) {
      $net = $extras['net'];
      if (isset($net[$provider]) && is_array($net[$provider])) {
        $r = (int)($net[$provider]['retries'] ?? -1);
        if ($r >= 0 && $r <= 5) return $r;
      }
    }
    return $fallback;
  }
}

/* ---------------- Options + net provider helpers (compat) --------------- */
if (!function_exists('options_defaults')) {
  /**
   * Defaults para opciones cuando no hay preferencias guardadas.
   */
  function options_defaults(): array {
    return [
      'provider'       => 'auto',
      'expiry_rule'    => 'nearest',    // regla genérica; el endpoint puede afinarla
      'strikes_count'  => 20,           // cantidad de strikes alrededor de ATM
      'price_source'   => 'last',       // fuente para ATM por defecto
    ];
  }
}

if (!function_exists('net_for_provider')) {
  /**
   * Devuelve timeout/retries efectivos para un proveedor según settings del usuario.
   */
  function net_for_provider(int $userId, string $provider): array {
    $timeout = function_exists('user_net_timeout_ms') ? user_net_timeout_ms($userId, $provider, 8000) : 8000;
    $retries = function_exists('user_net_retries') ? user_net_retries($userId, $provider, 2) : 2;
    return [ 'timeout_ms' => (int)$timeout, 'retries' => (int)$retries ];
  }
}

if (!function_exists('http_get_json_retry')) {
  /**
   * Compat wrapper: acepta timeout en ms y reintentos, llama a http_get_json_with_retries.
   */
  function http_get_json_retry(string $url, int $timeout_ms = 8000, int $retries = 2): array {
    $sec = max(3, (int)ceil($timeout_ms / 1000));
    if (function_exists('http_get_json_with_retries')) {
      return http_get_json_with_retries($url, [], $sec, max(0, (int)$retries));
    }
    // Fallback sin reintentos explícitos
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $sec,
      CURLOPT_TIMEOUT        => $sec,
      CURLOPT_HTTPHEADER     => ['Accept: application/json','User-Agent: '.http_user_agent()],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) json_error('upstream-http-'.(int)$code, 502);
    $resp = json_decode((string)$body, true);
    return is_array($resp) ? $resp : [];
  }
}

if (!function_exists('app_timezone_for_user')) {
  /**
   * Devuelve la zona horaria preferida del usuario.
   * Por compatibilidad, si no hay preferencia guardada, retorna 'UTC'.
   * Nota: si en el futuro guardas un identificador IANA en settings (p. ej. 'America/Mexico_City'),
   * puedes leerlo aquí via get_user_setting($userId, 'tz_name').
   */
  function app_timezone_for_user(int $userId): string {
    // Intentar leer offset simple (minutos) o nombre de tz desde prefs file si existiera
    try {
      $tzName = get_user_setting($userId, 'tz_name');
      if (is_string($tzName) && $tzName !== '') return $tzName;
      $tzOff = get_user_setting($userId, 'tz_offset');
      if (is_string($tzOff) && $tzOff !== '') {
        // Aceptar formatos "+HH:MM", "-HH:MM", o minutos numéricos
        $offMin = null;
        if (preg_match('/^([+-])(\d{1,2})(?::?(\d{2}))?$/', trim($tzOff), $m)) {
          $sign = $m[1] === '-' ? -1 : 1; $hh = (int)$m[2]; $mm = isset($m[3]) ? (int)$m[3] : 0;
          $offMin = $sign * ($hh*60 + $mm);
        } elseif (is_numeric($tzOff)) {
          $offMin = (int)$tzOff;
        }
        if ($offMin !== null) {
          // Mapear offset a un nombre Etc/GMT (nota: señal invertida en Etc/GMT)
          $hours = (int)round($offMin/60);
          $etc = 'Etc/GMT' . ($hours === 0 ? '' : ($hours>0 ? sprintf('-%d', $hours) : sprintf('+%d', -$hours)));
          if (@timezone_open($etc)) return $etc;
        }
      }
    } catch (Throwable $e) {}
    // Default enfocado a mercado de NYSE
    return 'America/New_York';
  }
}

if (!function_exists('market_hours_nyse')) {
  /**
   * Devuelve horarios de mercado NYSE para el día de hoy (en TZ dada) y si está abierto ahora.
   * No contempla feriados; sólo horario regular 09:30–16:00.
   */
  function market_hours_nyse(string $tz = 'America/New_York'): array {
    try {
      $tzObj = @timezone_open($tz) ? new DateTimeZone($tz) : new DateTimeZone('America/New_York');
      $now   = new DateTime('now', $tzObj);
      $d     = $now->format('Y-m-d');
      $open  = new DateTime($d.' 09:30:00', $tzObj);
      $close = new DateTime($d.' 16:00:00', $tzObj);
      $isOpen = ($now >= $open && $now <= $close);
      return [
        'tz'      => $tzObj->getName(),
        'now'     => $now->format(DateTime::ATOM),
        'open'    => $open->format(DateTime::ATOM),
        'close'   => $close->format(DateTime::ATOM),
        'is_open' => $isOpen,
      ];
    } catch (Throwable $e) {
      return [ 'tz' => $tz, 'error' => $e->getMessage() ];
    }
  }
}

/* -------------------- HTTP JSON with configurable retries ------------------- */
if (!function_exists('http_get_json_with_retries')) {
  /**
   * Realiza GET con Accept: application/json y reintentos exponenciales simples.
   * Retries: número de reintentos adicionales (0 = solo 1 intento).
   */
  function http_get_json_with_retries(string $url, array $headers = [], int $timeout = 12, int $retries = 0): array {
    $attempts = max(1, (int)$retries + 1);
    $ua = http_user_agent();
    for ($i = 1; $i <= $attempts; $i++) {
      $ch = curl_init($url);
      $hdrs = [];
      $hdrs[] = 'Accept: application/json';
      foreach ($headers as $k => $v) $hdrs[] = $k . ': ' . $v;
      $hdrs[] = 'User-Agent: ' . $ua;
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_FOLLOWLOCATION    => true,
        CURLOPT_CONNECTTIMEOUT    => $timeout,
        CURLOPT_TIMEOUT           => $timeout,
        CURLOPT_HTTPHEADER        => $hdrs,
      ]);
      $body = curl_exec($ch);
      $err  = ($body === false) ? curl_error($ch) : '';
      $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);

      // Éxito
      if ($body !== false && $code >= 200 && $code < 300) {
        $trim = ltrim((string)$body);
        if ($trim !== '' && str_starts_with($trim, '<')) {
          if ($i === $attempts) json_error('upstream-non-json', 502, substr($trim, 0, 200));
        } else {
          $resp = ($trim === '') ? [] : json_decode($trim, true);
          if (is_array($resp) || $trim === '') return $resp ?? [];
          if ($i === $attempts) json_error('upstream-non-json', 502, substr($trim, 0, 200));
        }
      }

      // Fallo: si última vuelta, emitir error consistente
      if ($i === $attempts) {
        if ($body === false) json_error('upstream-error', 502, $err ?: 'curl_exec false');
        json_error('upstream-http-' . (int)$code, 502);
      }

      // Backoff sencillo: 100ms, 200ms, 400ms...
      $sleepUs = (int)(100000 * (2 ** ($i - 1)));
      usleep(min($sleepUs, 800000));
    }
    // No debería alcanzarse
    json_error('upstream-unknown', 502);
  }
}

// Fin helpers.php

/* ===================== User settings helpers (file-based) ===================== */
if (!function_exists('get_user_setting')) {
  function get_user_setting(int $userId, string $key): ?string {
    $dir = __DIR__ . '/data';
    $path = $dir . '/user_prefs.' . $userId . '.json';
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : [];
    if (!is_array($data)) return null;
    // options_prefs: empaquetar de extras a un JSON
    if ($key === 'options_prefs') {
      $opt = [
        'provider'     => $data['options_provider']      ?? 'auto',
        'expiry_rule'  => $data['options_expiry_rule']   ?? null,
        'strikes_count'=> $data['options_strike_count']  ?? null,
        'price_source' => $data['atm_price_source']      ?? null,
      ];
      return json_encode($opt, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    // valor directo si existe
    return array_key_exists($key, $data) ? (is_scalar($data[$key]) ? (string)$data[$key] : json_encode($data[$key], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) : null;
  }
}

if (!function_exists('set_user_setting')) {
  function set_user_setting(int $userId, string $key, $value): bool {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/user_prefs.' . $userId . '.json';
    $data = [];
    if (is_file($path)) {
      $raw = @file_get_contents($path);
      $tmp = $raw ? json_decode($raw, true) : [];
      if (is_array($tmp)) $data = $tmp;
    }
    $data[$key] = $value;
    return (bool)@file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
  }
}
