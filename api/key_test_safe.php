<?php
// /bolsa/api/key_test_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crypto.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error' => 'invalid-user'], 401);

  $in       = json_input();
  $provider = strtolower(trim((string)($in['provider'] ?? '')));
  $rawKey   = trim((string)($in['api_key'] ?? '')); // opcional: si viene vacía, usa la guardada

  if ($provider === '') json_out(['error'=>'provider-required'], 400);

  // Resuelve clave: si no vino en el body, intenta la guardada en BD (o env)
  $providersEnv = [
    'gemini'       => 'GEMINI_API_KEY',
    'openai'       => 'OPENAI_API_KEY',
    'xai'          => 'XAI_API_KEY',
    'claude'       => 'ANTHROPIC_API_KEY',
    'deepseek'     => 'DEEPSEEK_API_KEY',
    'tiingo'       => 'TIINGO_API_KEY',
    'finnhub'      => 'FINNHUB_API_KEY',
    'alphavantage' => 'ALPHAVANTAGE_API_KEY',
    'polygon'      => 'POLYGON_API_KEY',
  ];
  if (!isset($providersEnv[$provider])) {
    json_out(['error'=>'unsupported-provider','detail'=>$provider], 400);
  }

  if ($rawKey === '') {
    $rawKey = get_api_key_for($userId, $provider, $providersEnv[$provider]);
  }
  if ($rawKey === '') {
    json_out(['error'=>'missing-key','detail'=>'No hay clave para ' . $provider], 400);
  }

  // Helper cURL simple
  $curl = function (string $url, array $opt = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opt + [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_TIMEOUT        => 8,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) return [0, $err, ''];
    return [$code, null, (string)$body];
  };

  $ok = false; $detail = ''; $last4 = substr($rawKey, -4);

  switch ($provider) {
    case 'gemini': {
      // Ping ligero con generateContent (gratis y corto)
      $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode($rawKey);
      $payload = json_encode(['contents' => [['parts' => [['text' => 'ping']]]]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      [$code, $err, $body] = $curl($url, [
        CURLOPT_POST       => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
      ]);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "Gemini HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'openai': {
      // Listar modelos no consume tokens y valida Bearer
      $url = 'https://api.openai.com/v1/models';
      [$code, $err, $body] = $curl($url, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$rawKey],
      ]);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "OpenAI HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'claude': {
      // Anthropic: listar modelos
      $url = 'https://api.anthropic.com/v1/models';
      [$code, $err, $body] = $curl($url, [
        CURLOPT_HTTPHEADER => [
          'x-api-key: '.$rawKey,
          'anthropic-version: 2023-06-01',
        ],
      ]);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "Anthropic HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'deepseek': {
      // DeepSeek: listar modelos (OpenAI-compatible)
      $url = 'https://api.deepseek.com/v1/models';
      [$code, $err, $body] = $curl($url, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$rawKey],
      ]);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "DeepSeek HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'xai': {
      // Listar modelos
      $url = 'https://api.x.ai/v1/models';
      [$code, $err, $body] = $curl($url, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$rawKey],
      ]);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "xAI HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'tiingo': {
      // Ping muy barato
      $url = 'https://api.tiingo.com/tiingo/daily/spy/prices?token='.rawurlencode($rawKey).'&startDate=2024-01-02&endDate=2024-01-03';
      [$code, $err, $body] = $curl($url);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "Tiingo HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'finnhub': {
      // Ping barato
      $url = 'https://finnhub.io/api/v1/quote?symbol=AAPL&token='.rawurlencode($rawKey);
      [$code, $err, $body] = $curl($url);
      if ($code >= 200 && $code < 300) $ok = true;
      else $detail = $err ?: "Finnhub HTTP $code: ".substr($body,0,160);
      break;
    }

    case 'alphavantage': {
      // Usamos SYMBOL_SEARCH que es ligero y funciona para validar key
      $url = 'https://www.alphavantage.co/query?function=SYMBOL_SEARCH&keywords=ibm&apikey='.rawurlencode($rawKey);
      [$code, $err, $body] = $curl($url);
      if ($code >= 200 && $code < 300) {
        // Si vino "Note" por rate limit igual consideramos OK (la key es válida)
        // Si trae "Invalid API key" lo marcamos error
        if (stripos($body, 'Invalid') !== false || stripos($body, 'error') !== false) {
          $detail = "AlphaVantage dice inválida: ".substr($body,0,160);
        } else {
          $ok = true;
        }
      } else {
        $detail = $err ?: "AlphaVantage HTTP $code: ".substr($body,0,160);
      }
      break;
    }

    case 'polygon': {
      // Endpoint muy barato
      $url = 'https://api.polygon.io/v3/reference/tickers?limit=1&apiKey='.rawurlencode($rawKey);
      [$code, $err, $body] = $curl($url);
      if ($code >= 200 && $code < 300) {
        if (stripos($body, '"error"') !== false) {
          $detail = "Polygon error: ".substr($body,0,160);
        } else {
          $ok = true;
        }
      } else {
        $detail = $err ?: "Polygon HTTP $code: ".substr($body,0,160);
      }
      break;
    }
  }

  if (!$ok) json_out(['ok'=>false,'error'=>'probe-failed','detail'=>$detail, 'provider'=>$provider], 400);
  json_out(['ok'=>true,'provider'=>$provider,'last4'=>$last4], 200);

} catch (Throwable $e) {
  json_out(['error'=>'key-test-failed','detail'=>$e->getMessage()], 500);
}
