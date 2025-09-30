<?php
// /bolsa/api/secrets_set_safe.php
// Guarda (o actualiza) API keys por usuario y preferencias de opciones/red.
// - Claves: se cifran y se guardan en la tabla user_api_keys (por usuario).
// - Preferencias: se guardan en user_settings (keys: options_prefs, net_prefs).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php'; // encrypt_text()
require_once __DIR__ . '/Crypto_safe.php'; // catai_encrypt() - nuevo sistema
require_once __DIR__ . '/db.php';     // db() o db_connect() según tu implementación

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . cfg('ALLOWED_ORIGIN', '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  $user = require_user(); // { id, email, ... }
  $userId = (int)$user['id'];

  $pdo = db();

  // Crea tabla user_api_keys si no existe (idempotente)
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(32) NOT NULL,
    api_key_enc LONGTEXT NOT NULL,
    key_ciphertext LONGTEXT NULL,
    key_fingerprint VARCHAR(64) NULL,
    last4 VARCHAR(4) NULL,
    status ENUM('active', 'inactive', 'revoked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_provider (user_id, provider),
    INDEX idx_user (user_id),
    INDEX idx_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $body = read_json_body();
  $secrets = isset($body['secrets']) && is_array($body['secrets']) ? $body['secrets'] : [];

  // Mapa de campos del Front → (provider, key_name)
  // En esta app solo guardamos 'api_key' por proveedor; si necesitas más, se puede extender.
  $fieldMap = [
    'tiingo_api_key'       => ['tiingo','api_key'],
    'alphavantage_api_key' => ['alphavantage','api_key'],
    'finnhub_api_key'      => ['finnhub','api_key'],
    'polygon_api_key'      => ['polygon','api_key'],
    'openai_api_key'       => ['openai','api_key'],
    'gemini_api_key'       => ['gemini','api_key'],
    'xai_api_key'          => ['xai','api_key'], // Grok (xAI)
  ];

  // UPSERT de claves (cifradas) con campos de auditoría
  if (!empty($secrets)) {
    $up = $pdo->prepare("INSERT INTO user_api_keys (user_id, provider, api_key_enc, key_ciphertext, key_fingerprint, last4, status, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                         ON DUPLICATE KEY UPDATE 
                           api_key_enc=VALUES(api_key_enc), 
                           key_ciphertext=VALUES(key_ciphertext),
                           key_fingerprint=VALUES(key_fingerprint),
                           last4=VALUES(last4),
                           status='active',
                           updated_at=NOW()");
    foreach ($fieldMap as $field => [$provider, $keyName]) {
      if (!isset($secrets[$field])) continue;
      $val = trim((string)$secrets[$field]);
      if ($val === '') continue;
      $enc = catai_encrypt($val); // usa sistema de cifrado externo
      if (!$enc) throw new Exception('Encrypt error for provider '.$provider);
      
      // Calcular campos de auditoría
      $keyFingerprint = hash('sha256', $val);
      $last4 = substr($val, -4);
      
      $up->execute([$userId, $provider, $enc, $enc, $keyFingerprint, $last4]);
    }
  }

  // Preferencias de opciones (opcional)
  // Estructura esperada:
  // {
  //   provider: 'auto' | 'polygon' | 'finnhub',
  //   expiry_rule: 'nearest_friday',
  //   strikes_count: 20,
  //   price_source: 'series_last' | 'provider'
  // }
  if (isset($body['options_prefs']) && is_array($body['options_prefs'])) {
    $def = [
      'provider'      => cfg('OPTIONS_DEFAULT_PROVIDER', 'auto'),
      'expiry_rule'   => cfg('OPTIONS_DEFAULT_EXPIRY_RULE', 'nearest_friday'),
      'strikes_count' => (int) cfg('OPTIONS_DEFAULT_STRIKES_COUNT', 20),
      'price_source'  => cfg('OPTIONS_DEFAULT_PRICE_SOURCE', 'series_last')
    ];
    $opts = array_merge($def, $body['options_prefs']);
    set_user_setting($userId, 'options_prefs', json_encode($opts, JSON_UNESCAPED_SLASHES));
  }

  // Preferencias de red (timeouts/retries) + zona horaria (opcional)
  // Estructura esperada:
  // {
  //   timezone: "America/Chicago",
  //   providers: {
  //     polygon:      {timeout_ms:8000, retries:2},
  //     finnhub:      {timeout_ms:8000, retries:2},
  //     tiingo:       {timeout_ms:8000, retries:2},
  //     alphavantage: {timeout_ms:8000, retries:2},
  //     openai:       {timeout_ms:8000, retries:0},
  //     gemini:       {timeout_ms:8000, retries:0},
  //     xai:          {timeout_ms:8000, retries:0}
  //   }
  // }
  if (isset($body['net_prefs']) && is_array($body['net_prefs'])) {
    $defaults = [
      'timezone' => cfg('APP_TIMEZONE', 'America/Chicago'),
      'providers' => [
        'polygon'      => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
        'finnhub'      => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
        'tiingo'       => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
        'alphavantage' => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
        'openai'       => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>0],
        'gemini'       => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>0],
        'xai'          => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>0],
      ]
    ];
    $np = $body['net_prefs'];

    // merge básico y seguro
    $merged = $defaults;
    if (isset($np['timezone']) && is_string($np['timezone']) && $np['timezone'] !== '') {
      $merged['timezone'] = $np['timezone'];
    }
    if (isset($np['providers']) && is_array($np['providers'])) {
      foreach ($np['providers'] as $k=>$v) {
        if (!isset($merged['providers'][$k])) {
          $merged['providers'][$k] = ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)];
        }
        if (isset($v['timeout_ms'])) $merged['providers'][$k]['timeout_ms'] = (int)$v['timeout_ms'];
        if (isset($v['retries']))    $merged['providers'][$k]['retries']    = (int)$v['retries'];
      }
    }
    set_user_setting($userId, 'net_prefs', json_encode($merged, JSON_UNESCAPED_SLASHES));
  }

  json_out(['ok' => true]);
} catch (Throwable $e) {
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 500);
}
