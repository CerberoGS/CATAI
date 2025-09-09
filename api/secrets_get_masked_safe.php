<?php
// /bolsa/api/secrets_get_masked_safe.php
// Devuelve claves enmascaradas + preferencias del usuario (opciones y red).

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . cfg('ALLOWED_ORIGIN', '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  $user = require_user(); // requiere JWT válido
  $userId = (int)$user['id'];

  // Providers soportados en esta app
  $providers = [
    'tiingo',
    'alphavantage',
    'finnhub',
    'polygon',
    'openai',   // IA
    'gemini',   // IA
    'xai',      // IA (Grok)
  ];

  // Mapa para el nombre de campo que espera el front
  $fieldMap = [
    'tiingo'       => 'tiingo_api_key',
    'alphavantage' => 'alphavantage_api_key',
    'finnhub'      => 'finnhub_api_key',
    'polygon'      => 'polygon_api_key',
    'openai'       => 'openai_api_key',
    'gemini'       => 'gemini_api_key',
    'xai'          => 'xai_api_key',
  ];

  $secrets_masked = [];
  $available = [];

  foreach ($providers as $p) {
    // get_api_key_for busca primero en BD (user_api_keys) y si no, intenta fallback global (config.php)
    $plain = get_api_key_for($userId, $p);
    if (!empty($plain)) {
      $secrets_masked[$fieldMap[$p]] = mask_secret($plain);
      $available[] = $p;
    } else {
      $secrets_masked[$fieldMap[$p]] = null;
    }
  }

  // Preferencias: options_prefs y net_prefs con fallback a defaults
  // options_defaults(): provider/expiry_rule/strikes_count/price_source (desde config.php si no hay user)
  $opt_raw = get_user_setting($userId, 'options_prefs');
  $opt_prefs = $opt_raw ? json_decode($opt_raw, true) : [];
  if (!is_array($opt_prefs)) $opt_prefs = [];
  $opt_prefs = array_merge(options_defaults(), $opt_prefs);

  // net_prefs: timezone + timeouts/retries por proveedor
  $net_raw = get_user_setting($userId, 'net_prefs');
  $net_prefs = $net_raw ? json_decode($net_raw, true) : [];
  if (!is_array($net_prefs)) $net_prefs = [];

  // Garantiza timezone y estructura providers con defaults si faltan
  $timezone = isset($net_prefs['timezone']) && is_string($net_prefs['timezone']) && $net_prefs['timezone'] !== ''
    ? $net_prefs['timezone']
    : cfg('APP_TIMEZONE', 'America/Chicago');

  $net_defaults = [
    'polygon'      => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
    'finnhub'      => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
    'tiingo'       => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
    'alphavantage' => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>cfg('NET_DEFAULT_RETRIES',2)],
    'openai'       => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>0],
    'gemini'       => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>0],
    'xai'          => ['timeout_ms'=>cfg('NET_DEFAULT_TIMEOUT_MS',8000),'retries'=>0],
  ];

  $net_prov = is_array($net_prefs['providers'] ?? null) ? $net_prefs['providers'] : [];
  // merge defaults -> user
  foreach ($net_defaults as $k=>$def) {
    if (!isset($net_prov[$k]) || !is_array($net_prov[$k])) $net_prov[$k] = $def;
    else {
      $net_prov[$k]['timeout_ms'] = isset($net_prov[$k]['timeout_ms']) ? (int)$net_prov[$k]['timeout_ms'] : $def['timeout_ms'];
      $net_prov[$k]['retries']    = isset($net_prov[$k]['retries'])    ? (int)$net_prov[$k]['retries']    : $def['retries'];
    }
  }

  $net_prefs_out = [
    'timezone'  => $timezone,
    'providers' => $net_prov
  ];

  json_out([
    'ok' => true,
    'secrets_masked'      => $secrets_masked,   // para placeholders en UI (enmascarado)
    'available_providers' => $available,        // para mostrar solo proveedores con key en análisis
    'options_prefs'       => $opt_prefs,
    'net_prefs'           => $net_prefs_out
  ]);
} catch (Throwable $e) {
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 500);
}
