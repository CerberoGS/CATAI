<?php
// /bolsa/api/options_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/quota.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // --- Auth + cuota -------------------------------------------------------
    $user = require_user();
    quota_check_and_log($user['id'], 'options', 1);
    $userId = (int)$user['id'];

    // --- Params -------------------------------------------------------------
    // Soporto GET/POST. Mantengo nombres existentes y agrego algunos nuevos.
    $symbol           = strtoupper(trim($_GET['symbol'] ?? $_POST['symbol'] ?? ''));
    $providerParam    = strtolower(trim($_GET['provider'] ?? $_POST['provider'] ?? 'auto'));
    $realtime         = filter_var($_GET['realtime'] ?? $_POST['realtime'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $wantGreeks       = filter_var($_GET['greeks']   ?? $_POST['greeks']   ?? 'true',  FILTER_VALIDATE_BOOLEAN);
    // Nuevo opcional: precio del subyacente para centrar ATM
    $underlyingPrice  = isset($_GET['underlying_price']) ? floatval($_GET['underlying_price']) :
                        (isset($_POST['underlying_price']) ? floatval($_POST['underlying_price']) : null);

    if ($symbol === '') {
        json_out(['error' => 'symbol_required'], 400);
    }

    // --- Preferencias del usuario (provider/expiry_rule/strikes_count/price_source)
    $optDefaults = options_defaults();
    $optRaw = get_user_setting($userId, 'options_prefs');
    $optUser = $optRaw ? json_decode($optRaw, true) : [];
    if (!is_array($optUser)) $optUser = [];
    $opt = array_merge($optDefaults, $optUser);

    // --- Zona horaria por usuario -------------------------------------------
    date_default_timezone_set(app_timezone_for_user($userId));

    // --- Resolución de proveedor (Auto → Polygon si hay key; sino Finnhub) ---
    $polygonKey = get_api_key_for($userId, 'polygon', 'POLYGON_API_KEY');
    $finnhubKey = get_api_key_for($userId, 'finnhub', 'FINNHUB_API_KEY');

    $provider = $providerParam ?: ($opt['provider'] ?? 'auto');
    if ($provider === 'auto') {
        $provider = $polygonKey ? 'polygon' : 'finnhub';
    } elseif ($provider === 'polygon' && !$polygonKey) {
        // Si forzó polygon y no hay key → caigo a finnhub para no romper
        $provider = 'finnhub';
    } elseif ($provider === 'finnhub' && !$finnhubKey && $polygonKey) {
        // Si forzó finnhub pero no hay key y sí hay polygon → usa polygon
        $provider = 'polygon';
    }

    // --- Timeouts/reintentos por proveedor ----------------------------------
    $net = net_for_provider($userId, $provider);
    $timeout_ms = (int)($net['timeout_ms'] ?? 8000);
    $retries    = (int)($net['retries']    ?? 2);

    // --- 1) Obtener expiraciones --------------------------------------------
    $expirations = [];

    if ($provider === 'polygon') {
        if (!$polygonKey) json_out(['error' => 'providers_unconfigured', 'detail' => 'Falta POLYGON API key'], 400);

        // Polygon: listar contratos y derivar expiraciones
        $url = "https://api.polygon.io/v3/reference/options/contracts"
             . "?underlying_ticker=" . urlencode($symbol)
             . "&limit=1000"
             . "&apiKey=" . urlencode($polygonKey);

        // GET expirations via Polygon with retries (seconds-based)
        if (function_exists('http_get_json_with_retries')) {
            $res = http_get_json_with_retries($url, [], max(3, (int)ceil($timeout_ms/1000)), (int)$retries);
        } else {
            // Fallback legacy helper name if present
            $res = function_exists('http_get_json_retry') ? http_get_json_retry($url, (int)$timeout_ms, (int)$retries) : [];
        }
        $results = $res['results'] ?? [];
        $expirations = array_values(array_unique(array_map(
            fn($r) => $r['expiration_date'] ?? null,
            $results
        )));
        $expirations = array_values(array_filter($expirations));
        sort($expirations);
    } else {
        if (!$finnhubKey) json_out(['error' => 'providers_unconfigured', 'detail' => 'Falta FINNHUB API key'], 400);

        // Finnhub: intentamos múltiples endpoints (como tu versión) y normalizamos expiraciones
        $candidates = [
            "https://finnhub.io/api/v1/stock/option-chain?symbol=" . rawurlencode($symbol),
            "https://finnhub.io/api/v1/option/chain?symbol="       . rawurlencode($symbol),
            "https://finnhub.io/api/v1/stock/options?symbol="      . rawurlencode($symbol),
        ];

        $resp = null; $httpCode = 0; $rawBody = null; $lastErr = null;
        foreach ($candidates as $base) {
            $url = $base . (str_contains($base, '?') ? '&' : '?') . "token=" . rawurlencode($finnhubKey);
            try {
                // Compat: tu helpers.php original aceptaba (url, headers=[], timeout=20, &$code=null, &$raw=null)
                // pero también tenemos http_get_json_retry(...). Para consistencia de timeouts usamos retry:
                if (function_exists('http_get_json_with_retries')) {
                    $resp = http_get_json_with_retries($url, [], max(3, (int)ceil($timeout_ms/1000)), (int)$retries);
                } else {
                    $resp = function_exists('http_get_json_retry') ? http_get_json_retry($url, (int)$timeout_ms, (int)$retries) : null;
                }
                if (is_array($resp)) break;
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        if (!is_array($resp)) {
            $detail = $lastErr ?: 'No response from Finnhub';
            json_out(['error' => 'internal_exception', 'detail' => $detail], 500);
        }

        if (isset($resp['expirations']) && is_array($resp['expirations'])) {
            $expirations = $resp['expirations'];
        } elseif (isset($resp['data']) && is_array($resp['data'])) {
            $expirations = $resp['data'];
        } else {
            $expirations = [];
        }
        $expirations = array_values(array_filter($expirations));
        sort($expirations);
    }

    if (empty($expirations)) {
        json_out([
            'ok'          => true,
            'provider'    => $provider,
            'symbol'      => $symbol,
            'expirations' => [],
            'contracts'   => [],
            'note'        => 'Sin expiraciones disponibles'
        ], 200);
    }

    // --- 2) Elegir expiración (viernes ≥ hoy) --------------------------------
    $rule = strtolower($opt['expiry_rule'] ?? 'nearest_friday');
    $chosen = null;

    if ($rule === 'nearest_friday') {
        $today = new DateTime('today');
        foreach ($expirations as $ex) {
            $dt = new DateTime($ex);
            if ((int)$dt->format('N') !== 5) continue; // 5 = viernes
            if ($dt < $today) continue;
            $chosen = $ex;
            break;
        }
        if (!$chosen) {
            $fridays = array_values(array_filter($expirations, fn($ex) => ((int)(new DateTime($ex))->format('N')) === 5));
            $chosen = $fridays[0] ?? $expirations[0];
        }
    } else {
        $chosen = $expirations[0]; // futuras reglas aquí
    }

    // --- 3) Contratos/strikes (Polygon) o chain normalizado (Finnhub) --------
    $contracts = [];
    $chain     = [];
    $note      = null;

    if ($provider === 'polygon') {
        // Pido contratos de esa expiración
        $url = "https://api.polygon.io/v3/reference/options/contracts"
             . "?underlying_ticker=" . urlencode($symbol)
             . "&expiration_date=" . urlencode($chosen)
             . "&limit=1000"
             . "&apiKey=" . urlencode($polygonKey);
        $res = http_get_json_retry($url, $timeout_ms, $retries);
        $list = $res['results'] ?? [];

        // Strikes disponibles
        $strikes = array_values(array_unique(array_map(
            fn($r) => isset($r['strike_price']) ? floatval($r['strike_price']) : null,
            $list
        )));
        $strikes = array_values(array_filter($strikes, fn($v) => $v !== null));
        sort($strikes, SORT_NUMERIC);

        // Centro ATM
        $center = null;
        if ($underlyingPrice !== null && $underlyingPrice > 0) {
            $center = $underlyingPrice;
        } else {
            // Si no hay precio, uso mediana de strikes para no bloquear
            $center = $strikes ? $strikes[intdiv(count($strikes), 2)] : null;
        }

        // Elegir N alrededor del ATM
        $n = max(1, (int)($opt['strikes_count'] ?? 20));
        $slice = [];
        if ($center !== null && !empty($strikes)) {
            $nearestIdx = null;
            foreach ($strikes as $i => $s) {
                if ($nearestIdx === null) { $nearestIdx = $i; continue; }
                if (abs($s - $center) < abs($strikes[$nearestIdx] - $center)) $nearestIdx = $i;
            }
            $half = intdiv($n, 2);
            $start = max(0, $nearestIdx - $half);
            $slice = array_slice($strikes, $start, $n);
        } else {
            $slice = array_slice($strikes, 0, min($n, count($strikes)));
        }

        $wanted = array_fill_keys(array_map(fn($s) => (string)$s, $slice), true);

        foreach ($list as $c) {
            $sp = isset($c['strike_price']) ? (string)$c['strike_price'] : null;
            if ($sp === null || !isset($wanted[$sp])) continue;

            $type  = strtolower($c['contract_type'] ?? '');
            $style = strtolower($c['exercise_style'] ?? '');

            $row = [
                'ticker' => $c['ticker'] ?? null,
                'type'   => $type,        // call/put
                'style'  => $style,       // american/european
                'strike' => isset($c['strike_price']) ? floatval($c['strike_price']) : null,
                'expiry' => $c['expiration_date'] ?? $chosen,
            ];
            $contracts[] = $row;

            // Construyo un "chain" mínimo compatible (sin bid/ask porque Polygon reference no lo trae aquí)
            $chain[] = [
                'provider'      => 'polygon',
                'symbol'        => $symbol,
                'expiration'    => $row['expiry'],
                'strike'        => $row['strike'],
                'type'          => $type === 'call' ? 'call' : 'put',
                'contract'      => $row['ticker'],
                'bid'           => null,
                'ask'           => null,
                'last'          => null,
                'volume'        => null,
                'open_interest' => null,
                'iv'            => null,
                'delta'         => null,
            ];
        }

        // Estrategia híbrida: si hay Finnhub key, enriquecer con cotizaciones/greeks
        if ($finnhubKey && count($chain)) {
            try {
                $fhTimeout = function_exists('user_net_timeout_ms') ? user_net_timeout_ms($userId, 'finnhub', (int)$timeout_ms) : (int)$timeout_ms;
                $fhRetries = function_exists('user_net_retries') ? user_net_retries($userId, 'finnhub', (int)$retries) : (int)$retries;

                $candidatesFH = [
                    "https://finnhub.io/api/v1/stock/option-chain?symbol=" . rawurlencode($symbol),
                    "https://finnhub.io/api/v1/option/chain?symbol="       . rawurlencode($symbol),
                    "https://finnhub.io/api/v1/stock/options?symbol="      . rawurlencode($symbol),
                ];
                $respFH = null; $lastErrFH = null;
                foreach ($candidatesFH as $baseFH) {
                    $urlFH = $baseFH . (str_contains($baseFH, '?') ? '&' : '?') . "token=" . rawurlencode($finnhubKey);
                    try {
                        if (function_exists('http_get_json_with_retries')) {
                            $respFH = http_get_json_with_retries($urlFH, [], max(3, (int)ceil($fhTimeout/1000)), (int)$fhRetries);
                        } elseif (function_exists('http_get_json_retry')) {
                            $respFH = http_get_json_retry($urlFH, (int)$fhTimeout, (int)$fhRetries);
                        }
                        if (is_array($respFH)) break;
                    } catch (Throwable $e) { $lastErrFH = $e->getMessage(); }
                }

                if (is_array($respFH)) {
                    // Normalizar posibles formas de respuesta de Finnhub
                    $rowsFH = [];
                    if (isset($respFH['data']) && is_array($respFH['data'])) {
                        $rowsFH = $respFH['data'];
                    } elseif (isset($respFH['chains']) && is_array($respFH['chains'])) {
                        $rowsFH = $respFH['chains'];
                    } elseif (isset($respFH['options']) && is_array($respFH['options'])) {
                        $rowsFH = $respFH['options'];
                    }

                    // Aplanar a lista comparable por expiración/tipo/strike
                    $flat = [];
                    foreach ($rowsFH as $rr) {
                        if (isset($rr['options']) && is_array($rr['options'])) {
                            $expFH = $rr['expirationDate'] ?? $rr['expiration'] ?? $rr['expiry'] ?? null;
                            foreach ($rr['options'] as $c2) {
                                $tt = strtolower((string)($c2['type'] ?? $c2['optionType'] ?? $c2['option_type'] ?? $c2['side'] ?? ''));
                                $type2 = ($tt === 'call' || $tt === 'c' || $tt === '1') ? 'call' : 'put';
                                $flat[] = [
                                    'expiration'    => $expFH,
                                    'type'          => $type2,
                                    'strike'        => isset($c2['strike']) ? (float)$c2['strike'] : null,
                                    'bid'           => isset($c2['bid']) ? (float)$c2['bid'] : null,
                                    'ask'           => isset($c2['ask']) ? (float)$c2['ask'] : null,
                                    'last'          => isset($c2['lastPrice']) ? (float)$c2['lastPrice'] : (isset($c2['last']) ? (float)$c2['last'] : null),
                                    'volume'        => isset($c2['volume']) ? (int)$c2['volume'] : null,
                                    'open_interest' => isset($c2['openInterest']) ? (int)$c2['openInterest'] : null,
                                    'iv'            => isset($c2['impliedVolatility']) ? (float)$c2['impliedVolatility'] : null,
                                    'delta'         => isset($c2['delta']) ? (float)$c2['delta'] : null,
                                ];
                            }
                        } else {
                            $tt = strtolower((string)($rr['type'] ?? $rr['optionType'] ?? $rr['option_type'] ?? $rr['side'] ?? ''));
                            $type2 = ($tt === 'call' || $tt === 'c' || $tt === '1') ? 'call' : 'put';
                            $flat[] = [
                                'expiration'    => $rr['expirationDate'] ?? $rr['expiration'] ?? $rr['expiry'] ?? null,
                                'type'          => $type2,
                                'strike'        => isset($rr['strike']) ? (float)$rr['strike'] : null,
                                'bid'           => isset($rr['bid']) ? (float)$rr['bid'] : null,
                                'ask'           => isset($rr['ask']) ? (float)$rr['ask'] : null,
                                'last'          => isset($rr['lastPrice']) ? (float)$rr['lastPrice'] : (isset($rr['last']) ? (float)$rr['last'] : null),
                                'volume'        => isset($rr['volume']) ? (int)$rr['volume'] : null,
                                'open_interest' => isset($rr['openInterest']) ? (int)$rr['openInterest'] : null,
                                'iv'            => isset($rr['impliedVolatility']) ? (float)$rr['impliedVolatility'] : null,
                                'delta'         => isset($rr['delta']) ? (float)$rr['delta'] : null,
                            ];
                        }
                    }

                    // Agrupar por expiración+tipo
                    $by = [];
                    foreach ($flat as $it) {
                        $exp = (string)($it['expiration'] ?? '');
                        $typ = (string)($it['type'] ?? '');
                        if ($exp === '' || $typ === '' || $it['strike'] === null) continue;
                        $by[$exp][$typ][] = $it;
                    }

                    // Enriquecer entradas Polygon con mejor match en Finnhub
                    foreach ($chain as &$ce) {
                        $expE = (string)($ce['expiration'] ?? '');
                        $typE = (string)($ce['type'] ?? '');
                        $strE = isset($ce['strike']) ? (float)$ce['strike'] : null;
                        if ($expE === '' || $typE === '' || $strE === null) continue;
                        if (!isset($by[$expE][$typE]) || !is_array($by[$expE][$typE])) continue;
                        $best = null; $bestDiff = 1e18;
                        foreach ($by[$expE][$typE] as $cand) {
                            $diff = abs(($cand['strike'] ?? 0.0) - $strE);
                            if ($diff < $bestDiff) { $bestDiff = $diff; $best = $cand; }
                        }
                        if ($best) {
                            if ($ce['bid']   === null && isset($best['bid']))   $ce['bid']   = $best['bid'];
                            if ($ce['ask']   === null && isset($best['ask']))   $ce['ask']   = $best['ask'];
                            if ($ce['last']  === null && isset($best['last']))  $ce['last']  = $best['last'];
                            if ($ce['iv']    === null && isset($best['iv']))    $ce['iv']    = $best['iv'];
                            if ($ce['delta'] === null && isset($best['delta'])) $ce['delta'] = $best['delta'];
                            if ($ce['volume'] === null && isset($best['volume'])) $ce['volume'] = $best['volume'];
                            if ($ce['open_interest'] === null && isset($best['open_interest'])) $ce['open_interest'] = $best['open_interest'];
                        }
                    }
                    unset($ce);

                    $note = 'Hybrid: Polygon strikes + Finnhub quotes/greeks';
                }
            } catch (Throwable $e) { /* si falla Finnhub, sigue Polygon puro */ }
        }

    } else {
        // Mantengo tu flujo y normalización FINNHUB (chain completo si lo da el plan)
        $candidates = [
            "https://finnhub.io/api/v1/stock/option-chain?symbol=" . rawurlencode($symbol),
            "https://finnhub.io/api/v1/option/chain?symbol="       . rawurlencode($symbol),
            "https://finnhub.io/api/v1/stock/options?symbol="      . rawurlencode($symbol),
        ];
        $resp = null; $lastErr = null;

        foreach ($candidates as $base) {
            $url = $base . (str_contains($base, '?') ? '&' : '?') . "token=" . rawurlencode($finnhubKey);
            try {
                $resp = http_get_json_retry($url, $timeout_ms, $retries);
                if (is_array($resp)) break;
            } catch (Throwable $e) {
                $lastErr = $e->getMessage();
            }
        }
        if (!is_array($resp)) {
            $detail = $lastErr ?: 'Finnhub: sin respuesta';
            json_out(['error' => 'internal_exception', 'detail' => $detail], 500);
        }

        $rows = [];
        if (isset($resp['data']) && is_array($resp['data'])) {
            $rows = $resp['data'];
        } elseif (isset($resp['chains']) && is_array($resp['chains'])) {
            $rows = $resp['chains'];
        } elseif (isset($resp['options']) && is_array($resp['options'])) {
            $rows = $resp['options'];
        }

        foreach ($rows as $r) {
            $t = strtolower((string)($r['type'] ?? $r['optionType'] ?? $r['option_type'] ?? $r['side'] ?? ''));
            $type = ($t === 'call' || $t === 'c' || $t === '1') ? 'call' : 'put';

            $chain[] = [
                'provider'      => 'finnhub',
                'symbol'        => $symbol,
                'expiration'    => $r['expirationDate'] ?? $r['expiration'] ?? $r['expiry'] ?? null,
                'strike'        => isset($r['strike']) ? (float)$r['strike'] : null,
                'type'          => $type,
                'contract'      => $r['symbol'] ?? $r['code'] ?? $r['contract'] ?? null,
                'bid'           => isset($r['bid']) ? (float)$r['bid'] : null,
                'ask'           => isset($r['ask']) ? (float)$r['ask'] : null,
                'last'          => isset($r['lastPrice']) ? (float)$r['lastPrice'] : (isset($r['last']) ? (float)$r['last'] : null),
                'volume'        => isset($r['volume']) ? (int)$r['volume'] : null,
                'open_interest' => isset($r['openInterest']) ? (int)$r['openInterest'] : null,
                'iv'            => $wantGreeks ? (isset($r['impliedVolatility']) ? (float)$r['impliedVolatility'] : null) : null,
                'delta'         => $wantGreeks ? (isset($r['delta']) ? (float)$r['delta'] : null) : null,
            ];
        }

        if (!count($chain)) {
            json_out([
                'provider'    => 'finnhub',
                'symbol'      => $symbol,
                'expiry'      => null,
                'expirations' => $expirations,
                'warning'     => 'empty_chain',
                'raw'         => $resp
            ], 200);
        }

        // Nota para tu UI: en tu plan, a veces Finnhub no expone strikes por contrato.
        $note = 'Finnhub: disponibilidad de strikes/greeks depende del plan.';
    }

    // --- Salida coherente y compatible --------------------------------------
    json_out([
        'ok'          => true,
        'provider'    => $provider,
        'symbol'      => $symbol,
        'realtime'    => $realtime,
        'greeks'      => $wantGreeks,
        'expiry'      => $chosen,
        'expirations' => $expirations,
        // Para Polygon, lista de contratos filtrados por strikes cercanos al ATM.
        // Para Finnhub, quedará vacío (sin romper UI que use 'chain').
        'contracts'   => $contracts,
        // Para compatibilidad con tu UI previa:
        // - Con Finnhub: el chain normalizado de siempre.
        // - Con Polygon: un chain mínimo con contract/type/strike/expiration.
        'chain'       => $chain,
        'note'        => $note
    ], 200);

} catch (Throwable $e) {
    json_out(['error' => 'internal_exception', 'detail' => $e->getMessage()], 500);
}
