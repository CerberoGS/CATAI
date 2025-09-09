<?php
// /bolsa/api/ai_analyze.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/quota.php';
require_once __DIR__ . '/crypto.php'; // <-- agrega esto

header('Content-Type: application/json; charset=utf-8');
// CORS lo maneja json_out(); si usas preflight:
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

try {
  // --- Auth + cuota ---
  $u = require_user();
  quota_check_and_log($u['id'], 'ai', 1);
  $userId = (int)$u['id'];

  // --- Body ---
  $body         = read_json_body();
  $providerReq  = strtolower((string)($body['provider'] ?? 'auto')); // 'auto' | 'gemini' | 'openai' | 'xai'
  $modelReq     = trim((string)($body['model'] ?? ''));              // opcional, depende del provider
  $prompt       = trim((string)($body['prompt'] ?? ''));
  $systemPrompt = isset($body['systemPrompt']) ? (string)$body['systemPrompt'] : null;
  if ($prompt === '') json_out(['error'=>'prompt_required'], 400);

  // --- Resolución de proveedor (auto: Gemini -> OpenAI -> xAI), usando keys por usuario ---
  $geminiKey   = get_api_key_for($userId, 'gemini',   'GEMINI_API_KEY');
  $openaiKey   = get_api_key_for($userId, 'openai',   'OPENAI_API_KEY');
  $xaiKey      = get_api_key_for($userId, 'xai',      'XAI_API_KEY');
  $claudeKey   = get_api_key_for($userId, 'claude',   'ANTHROPIC_API_KEY');
  $deepseekKey = get_api_key_for($userId, 'deepseek', 'DEEPSEEK_API_KEY');

  $provider = $providerReq;
  if ($provider === 'auto') {
    if ($geminiKey)         $provider = 'gemini';
    elseif ($openaiKey)     $provider = 'openai';
    elseif ($claudeKey)     $provider = 'claude';
    elseif ($xaiKey)        $provider = 'xai';
    elseif ($deepseekKey)   $provider = 'deepseek';
    else json_out(['error'=>'no_ai_keys','detail'=>'Configura una key (Gemini/OpenAI/Claude/xAI/DeepSeek) en tu panel'], 400);
  }

  // --- Timeouts y reintentos según preferencias del usuario ---
  $net       = net_for_provider($userId, $provider);
  $timeoutMs = (int)($net['timeout_ms'] ?? 8000);
  $retries   = (int)($net['retries']    ?? 0);

  // --- Llamadas por proveedor ---
  if ($provider === 'gemini') {
    if (!$geminiKey) json_out(['error'=>'no_gemini_key','detail'=>'Agrega tu GEMINI API key en configuración'], 400);
    $bad = preg_match('/gpt|grok|claude|deepseek/i', $modelReq ?? '');
    $model = (!$modelReq || $bad) ? 'gemini-2.0-flash' : $modelReq; // default seguro

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($geminiKey);

    // Construcción del payload:
    // - Tu versión anterior usaba contents.parts + systemInstruction opcional.
    $payload = [
      'contents' => [[ 'parts' => [['text' => $prompt]] ]],
    ];
    if ($systemPrompt) {
      // Dos opciones válidas; conservamos la tuya:
      $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT_MS     => $timeoutMs,
      CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) throw new Exception('cURL: ' . curl_error($ch));
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) throw new Exception("Gemini HTTP $code");

    $j = json_decode($resp, true);
    // Extraer todos los parts.text de todos los candidates
    $texts = [];
    if (isset($j['candidates']) && is_array($j['candidates'])) {
      foreach ($j['candidates'] as $cand) {
        $parts = $cand['content']['parts'] ?? [];
        if (is_array($parts)) {
          foreach ($parts as $p) {
            if (isset($p['text']) && is_string($p['text']) && trim($p['text']) !== '') {
              $texts[] = $p['text'];
            }
          }
        }
      }
    }
    $text = trim(implode("\n", $texts));
    if ($text === '') {
      // Señalizar motivo si viene bloqueado o sin contenido
      $detail = $j['promptFeedback']['blockReason'] ?? ($j['candidates'][0]['finishReason'] ?? 'empty_response');
      // Intentar fallback si hay otras keys configuradas
      if ($openaiKey) {
        // Reintentar con OpenAI
        $fallbackPayload = [
          'model' => ($modelReq !== '' ? $modelReq : 'gpt-4o-mini'),
          'messages' => array_values(array_filter([
            $systemPrompt ? ['role'=>'system','content'=>$systemPrompt] : null,
            ['role'=>'user','content'=>$prompt],
          ])),
          'temperature' => 0.2,
        ];
        $ch2 = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch2, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openaiKey,
          ],
          CURLOPT_POSTFIELDS     => json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
          CURLOPT_TIMEOUT_MS     => $timeoutMs,
          CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
        ]);
        $resp2 = curl_exec($ch2);
        if ($resp2 !== false) {
          $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
          curl_close($ch2);
          if ($code2 >= 200 && $code2 < 300) {
            $j2 = json_decode($resp2, true);
            $text2 = trim((string)($j2['choices'][0]['message']['content'] ?? ''));
            if ($text2 !== '') json_out(['text'=>$text2,'provider'=>'openai','model'=>($fallbackPayload['model'])], 200);
          } else {
            curl_close($ch2);
          }
        } else {
          curl_close($ch2);
        }
      } elseif ($xaiKey) {
        // Reintentar con xAI
        $fallbackPayload = [
          'model' => ($modelReq !== '' ? $modelReq : 'grok-2-mini'),
          'messages' => array_values(array_filter([
            $systemPrompt ? ['role'=>'system','content'=>$systemPrompt] : null,
            ['role'=>'user','content'=>$prompt],
          ])),
          'temperature' => 0.2,
        ];
        $ch2 = curl_init('https://api.x.ai/v1/chat/completions');
        curl_setopt_array($ch2, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST           => true,
          CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $xaiKey,
          ],
          CURLOPT_POSTFIELDS     => json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
          CURLOPT_TIMEOUT_MS     => $timeoutMs,
          CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
        ]);
        $resp2 = curl_exec($ch2);
        if ($resp2 !== false) {
          $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
          curl_close($ch2);
          if ($code2 >= 200 && $code2 < 300) {
            $j2 = json_decode($resp2, true);
            $text2 = trim((string)($j2['choices'][0]['message']['content'] ?? ''));
            if ($text2 !== '') json_out(['text'=>$text2,'provider'=>'xai','model'=>($fallbackPayload['model'])], 200);
          } else {
            curl_close($ch2);
          }
        } else {
          curl_close($ch2);
        }
      }
      // Sin fallback exitoso
      json_out(['text'=>'', 'provider'=>'gemini', 'model'=>$model, 'detail'=>$detail], 200);
    }
    json_out(['text'=>$text, 'provider'=>'gemini', 'model'=>$model], 200);
  }

  if ($provider === 'openai') {
    if (!$openaiKey) json_out(['error'=>'no_openai_key','detail'=>'Agrega tu OPENAI API key en configuración'], 400);
    // Sanear modelo: si el modelo no parece de OpenAI, usar uno sugerido
    $bad = preg_match('/gemini|grok|claude|deepseek/i', $modelReq ?? '');
    $model = (!$modelReq || $bad) ? 'gpt-4o-mini' : $modelReq;

    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $payload = [
      'model' => $model,
      'messages' => array_values(array_filter([
        $systemPrompt ? ['role'=>'system', 'content'=>$systemPrompt] : null,
        ['role'=>'user','content'=>$prompt],
      ])),
      'temperature' => 0.2,
    ];

    // Aumentar timeout mínimo a 20s para OpenAI; aplicar reintentos si configurado
    if ($timeoutMs < 20000) $timeoutMs = 20000;
    $attempts = max(1, $retries + 1);
    $lastErr = null; $lastCode = 0; $lastBody = '';
    for ($i=0; $i<$attempts; $i++) {
      $ch = curl_init($endpoint);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
          'Content-Type: application/json',
          'Authorization: Bearer ' . $openaiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT_MS     => $timeoutMs,
        CURLOPT_CONNECTTIMEOUT_MS => min(4000, $timeoutMs),
      ]);
      $resp = curl_exec($ch);
      if ($resp === false) {
        $lastErr = curl_error($ch); $lastCode = 0; curl_close($ch);
      } else {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $lastCode = $code; $lastBody = (string)$resp; curl_close($ch);
        if ($code >= 200 && $code < 300) {
          $j = json_decode($resp, true);
          $text = $j['choices'][0]['message']['content'] ?? '';
          json_out(['text'=>$text, 'provider'=>'openai', 'model'=>$model], 200);
        }
        // Reintentar sólo en errores temporales (timeout/ratelimit/5xx/408)
        if (!in_array($code, [408,429,500,502,503,504], true)) break;
      }
      if ($i < $attempts-1) usleep(400000); // 400ms backoff
    }
    if ($lastCode === 0 && $lastErr) json_out(['error'=>'openai_curl','detail'=>$lastErr, 'provider'=>'openai','model'=>$model], 200);
    $body = json_decode($lastBody, true);
    $detail = is_array($body) ? ($body['error']['message'] ?? substr((string)$lastBody,0,240)) : substr((string)$lastBody,0,240);
    json_out(['error'=>'openai_http_'.$lastCode, 'detail'=>$detail, 'provider'=>'openai','model'=>$model], 200);
  }

  if ($provider === 'claude') {
    if (!$claudeKey) json_out(['error'=>'no_claude_key','detail'=>'Agrega tu ANTHROPIC API key en configuración'], 400);
    $bad = preg_match('/gemini|gpt|grok|deepseek/i', $modelReq ?? '');
    $model = (!$modelReq || $bad) ? 'claude-3-5-sonnet-latest' : $modelReq;
    $endpoint = 'https://api.anthropic.com/v1/messages';
    $payload = [
      'model' => $model,
      'max_tokens' => 512,
      'system' => $systemPrompt ?: null,
      'messages' => [ ['role'=>'user','content'=>$prompt] ],
    ];
    // limpiar nulls
    if ($payload['system'] === null) unset($payload['system']);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $claudeKey,
        'anthropic-version: 2023-06-01',
      ],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT_MS     => $timeoutMs,
      CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); json_out(['error'=>'anthropic_curl','detail'=>$err,'provider'=>'claude','model'=>$model],200);}    
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { $detail = substr((string)$resp,0,200); json_out(['error'=>'anthropic_http_'.$code,'detail'=>$detail,'provider'=>'claude','model'=>$model],200);}    
    $j = json_decode($resp, true);
    $text = '';
    if (isset($j['content']) && is_array($j['content'])) {
      foreach ($j['content'] as $c) {
        if (($c['type'] ?? '') === 'text' && isset($c['text'])) $text .= ($text?"\n":"") . $c['text'];
      }
    }
    json_out(['text'=>trim($text), 'provider'=>'claude', 'model'=>$model], 200);
  }

  if ($provider === 'deepseek') {
    if (!$deepseekKey) json_out(['error'=>'no_deepseek_key','detail'=>'Agrega tu DEEPSEEK API key en configuración'], 400);
    $bad = preg_match('/gemini|gpt|grok|claude/i', $modelReq ?? '');
    $model = (!$modelReq || $bad) ? 'deepseek-chat' : $modelReq;
    $endpoint = 'https://api.deepseek.com/chat/completions';
    $payload = [
      'model' => $model,
      'messages' => array_values(array_filter([
        $systemPrompt ? ['role'=>'system','content'=>$systemPrompt] : null,
        ['role'=>'user','content'=>$prompt],
      ])),
      'temperature' => 0.2,
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $deepseekKey,
      ],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT_MS     => $timeoutMs,
      CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); json_out(['error'=>'deepseek_curl','detail'=>$err,'provider'=>'deepseek','model'=>$model],200);}    
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { $detail = substr((string)$resp,0,200); json_out(['error'=>'deepseek_http_'.$code,'detail'=>$detail,'provider'=>'deepseek','model'=>$model],200);}    
    $j = json_decode($resp, true);
    $text = $j['choices'][0]['message']['content'] ?? '';
    json_out(['text'=>$text, 'provider'=>'deepseek', 'model'=>$model], 200);
  }

  if ($provider === 'xai') {
    if (!$xaiKey) json_out(['error'=>'no_xai_key','detail'=>'Agrega tu XAI (Grok) API key en configuración'], 400);
    $bad = preg_match('/gemini|gpt|claude|deepseek/i', $modelReq ?? '');
    $model = (!$modelReq || $bad) ? 'grok-2-mini' : $modelReq;

    $endpoint = 'https://api.x.ai/v1/chat/completions';
    $payload = [
      'model' => $model,
      'messages' => array_values(array_filter([
        $systemPrompt ? ['role'=>'system','content'=>$systemPrompt] : null,
        ['role'=>'user','content'=>$prompt],
      ])),
      'temperature' => 0.2,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $xaiKey,
      ],
      CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT_MS     => $timeoutMs,
      CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $err = curl_error($ch); curl_close($ch); json_out(['error'=>'xai_curl','detail'=>$err,'provider'=>'xai','model'=>$model],200);}    
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { $detail = substr((string)$resp,0,200); json_out(['error'=>'xai_http_'.$code,'detail'=>$detail,'provider'=>'xai','model'=>$model],200);}    

    $j = json_decode($resp, true);
    $text = $j['choices'][0]['message']['content'] ?? '';
    json_out(['text'=>$text, 'provider'=>'xai', 'model'=>$model], 200);
  }

  json_out(['error'=>'unsupported_ai_provider'], 400);

} catch (Throwable $e) {
  json_out(['error'=>'ai_failed','detail'=>$e->getMessage()], 500);
}
