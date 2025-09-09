<?php
// /bolsa/api/secrets_test_safe.php
// Valida credenciales por proveedor para el usuario autenticado.
// Entrada (POST JSON): { "provider": "polygon" | "finnhub" | "tiingo" | "alphavantage" | "openai" | "gemini" | "xai" }
// Salida: { ok: bool, provider: string, detail?: string, error?: string }

require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . cfg('ALLOWED_ORIGIN', '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  $user = require_user();
  $userId = (int)$user['id'];

  $body = read_json_body();
  $provider = strtolower(trim((string)($body['provider'] ?? '')));

  if ($provider === '') {
    json_out(['ok'=>false,'error'=>'provider_required'], 400);
  }

  // Timeouts/reintentos por proveedor, configurables por el usuario
  $net = net_for_provider($userId, $provider);
  $timeout_ms = (int)($net['timeout_ms'] ?? 8000);
  $retries    = (int)($net['retries']    ?? 2);

  $out = ['provider'=>$provider, 'ok'=>false, 'detail'=>null];

  switch ($provider) {
    /* ───────────────────────────── Datos de mercado ───────────────────────────── */

    case 'polygon': {
      $key = get_api_key_for($userId, 'polygon', 'POLYGON_API_KEY');
      if (!$key) throw new Exception('missing_key');
      // Ping barato: referencia de contratos limit=1
      $url = "https://api.polygon.io/v3/reference/options/contracts?limit=1&apiKey=" . urlencode($key);
      $res = http_get_json_retry($url, $timeout_ms, $retries);
      // Esperamos status OK o al menos results array
      $ok = (isset($res['status']) && strtoupper($res['status']) === 'OK') || isset($res['results']);
      $out['ok'] = (bool)$ok;
      $out['detail'] = $res['status'] ?? 'OK';
      break;
    }

    case 'finnhub': {
      $key = get_api_key_for($userId, 'finnhub', 'FINNHUB_API_KEY');
      if (!$key) throw new Exception('missing_key');
      // Ping barato: noticias generales (no consume mucho y no requiere símbolo)
      $url = "https://finnhub.io/api/v1/news?category=general&token=" . urlencode($key);
      $res = http_get_json_retry($url, $timeout_ms, $retries);
      $out['ok'] = is_array($res);
      $out['detail'] = $out['ok'] ? 'Key válida' : 'Sin datos';
      break;
    }

    case 'tiingo': {
      $key = get_api_key_for($userId, 'tiingo', 'TIINGO_API_KEY');
      if (!$key) throw new Exception('missing_key');
      // Ping barato: daily AAPL metadata
      $url = "https://api.tiingo.com/tiingo/daily/aapl?token=" . urlencode($key);
      $res = http_get_json_retry($url, $timeout_ms, $retries);
      $out['ok'] = isset($res['ticker']);
      $out['detail'] = $out['ok'] ? $res['ticker'] : 'Sin datos';
      break;
    }

    case 'alphavantage': {
      $key = get_api_key_for($userId, 'alphavantage', 'ALPHAVANTAGE_API_KEY');
      if (!$key) throw new Exception('missing_key');
      // Ping barato: GLOBAL_QUOTE (IBM)
      $url = "https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=IBM&apikey=" . urlencode($key);
      $res = http_get_json_retry($url, $timeout_ms, $retries);
      $out['ok'] = isset($res['Global Quote']);
      $out['detail'] = $out['ok'] ? 'Key válida' : 'Sin datos';
      break;
    }

    /* ─────────────────────────────── Proveedores IA ───────────────────────────── */

    case 'openai': {
      // Validación no intrusiva: solo presencia de la key (evita costo)
      $key = get_api_key_for($userId, 'openai', 'OPENAI_API_KEY');
      if (!$key) throw new Exception('missing_key');
      $out['ok'] = true;
      $out['detail'] = 'Key presente';
      break;
    }

    case 'gemini': {
      $key = get_api_key_for($userId, 'gemini', 'GEMINI_API_KEY');
      if (!$key) throw new Exception('missing_key');
      $out['ok'] = true;
      $out['detail'] = 'Key presente';
      break;
    }

    case 'xai': { // Grok
      $key = get_api_key_for($userId, 'xai', 'XAI_API_KEY');
      if (!$key) throw new Exception('missing_key');
      $out['ok'] = true;
      $out['detail'] = 'Key presente';
      break;
    }

    default:
      json_out(['ok'=>false,'error'=>'provider_not_supported'], 400);
  }

  json_out($out, 200);

} catch (Throwable $e) {
  // errores típicos: missing_key, HTTP failed, invalid JSON, etc.
  json_out(['ok'=>false, 'error'=>$e->getMessage()], 400);
}
