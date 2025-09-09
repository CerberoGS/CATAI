<?php
/**
 * OPTIONS CHAIN endpoint.
 * Usa helpers.php para auth + HTTP y NO declara funciones utilitarias.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$u = require_user(); // endpoint protegido

$config = require __DIR__ . '/config.php';

// --------- Params ---------
$symbol   = strtoupper(trim($_GET['symbol'] ?? ''));
$provider = strtolower(trim($_GET['provider'] ?? 'auto'));
$realtime = (($_GET['realtime'] ?? 'false') === 'true');
$greeks   = (($_GET['greeks']   ?? 'true')  === 'true');

if ($symbol === '') {
  json_out(['error' => 'symbol_required'], 400);
}

// --------- Resolver proveedor ---------
$prov = $provider;
if ($prov === 'auto') {
  if (!empty($config['FINNHUB_API_KEY'])) {
    $prov = 'finnhub';
  } elseif (!empty($config['TIINGO_API_KEY'])) {
    // Tiingo no ofrece chain de opciones estándar en el plan común
    // (dejamos explícito para claridad)
    $prov = 'tiingo';
  } else {
    json_out([
      'error'  => 'providers_unconfigured',
      'detail' => 'Configura FINNHUB_API_KEY o TIINGO_API_KEY en api/config.php'
    ], 400);
  }
}

// --------- Fetch según proveedor ---------
switch ($prov) {
  case 'local':
    // No hay chain local
    json_out([
      'error'  => 'unsupported_provider',
      'detail' => 'provider=local no soporta opciones'
    ], 400);
    break;

  case 'tiingo':
    json_out([
      'error'  => 'unsupported_provider',
      'detail' => 'tiingo (opciones) no implementado en este endpoint'
    ], 400);
    break;

  case 'finnhub': {
    $key = $config['FINNHUB_API_KEY'] ?? '';
    if ($key === '') {
      json_out(['error' => 'no_finnhub_key'], 400);
    }
    // Doc Finnhub: /stock/option-chain?symbol=TSLA
    $url  = 'https://finnhub.io/api/v1/stock/option-chain'
          . '?symbol=' . rawurlencode($symbol)
          . '&token='  . rawurlencode($key);

    // Timeout y retries según preferencias del usuario (settings.data.net.finnhub)
    $uid = (int)($u['id'] ?? 0);
    $ms = function_exists('user_net_timeout_ms') ? user_net_timeout_ms($uid, 'finnhub', 25000) : 25000;
    $timeoutSec = max(3, (int)ceil($ms / 1000));
    $retries = function_exists('user_net_retries') ? user_net_retries($uid, 'finnhub', 0) : 0;

    // GET con reintentos
    $raw = function_exists('http_get_json_with_retries')
      ? http_get_json_with_retries($url, [], $timeoutSec, $retries)
      : http_get_json($url, [], $timeoutSec);

    // Normalizamos a una lista "chain" común: [{expiration,type,strike,bid,ask,iv,delta,contract}, ...]
    $chain = [];
    $data  = $raw['data'] ?? [];

    foreach ($data as $exp) {
      $expiration = $exp['expirationDate'] ?? ($exp['expiration'] ?? null);

      // Variantes de estructura que he visto:
      // 1) $exp['options'] = [{'type':'call'|'put', 'strike':..., 'bid':..., 'ask':..., 'impliedVolatility':..., 'delta':..., 'symbol':...}, ...]
      // 2) $exp['CALL'] y $exp['PUT'] (arrays separados)
      // 3) $exp['calls'] y $exp['puts']
      $buckets = [];

      if (isset($exp['options']) && is_array($exp['options'])) {
        $buckets[] = $exp['options'];
      }
      if (isset($exp['CALL']) && is_array($exp['CALL'])) {
        $buckets[] = array_map(function($x){ $x['type'] = 'call'; return $x; }, $exp['CALL']);
      }
      if (isset($exp['PUT']) && is_array($exp['PUT'])) {
        $buckets[] = array_map(function($x){ $x['type'] = 'put'; return $x; }, $exp['PUT']);
      }
      if (isset($exp['calls']) && is_array($exp['calls'])) {
        $buckets[] = array_map(function($x){ $x['type'] = $x['type'] ?? 'call'; return $x; }, $exp['calls']);
      }
      if (isset($exp['puts']) && is_array($exp['puts'])) {
        $buckets[] = array_map(function($x){ $x['type'] = $x['type'] ?? 'put'; return $x; }, $exp['puts']);
      }

      foreach ($buckets as $arr) {
        foreach ($arr as $c) {
          $chain[] = [
            'expiration' => $expiration,
            'type'       => strtolower($c['type'] ?? ''),
            'strike'     => $c['strike'] ?? null,
            'bid'        => $c['bid'] ?? null,
            'ask'        => $c['ask'] ?? null,
            'iv'         => $c['impliedVolatility'] ?? ($c['iv'] ?? null),
            'delta'      => $c['delta'] ?? null,
            'contract'   => $c['symbol'] ?? ($c['contractSymbol'] ?? null),
          ];
        }
      }
    }

    json_out([
      'provider' => 'finnhub',
      'symbol'   => $symbol,
      'realtime' => $realtime,
      'greeks'   => $greeks,
      'chain'    => $chain,
      'raw'      => $raw,        // deja el crudo para debug; si no quieres, quítalo
    ]);
    break;
  }

  default:
    json_out(['error' => 'unsupported_provider', 'detail' => $prov], 400);
}
