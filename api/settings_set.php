<?php
// /bolsa/api/settings_set.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)$u['id'];
  $in = read_json_body();

  // Logger
  $logDir = __DIR__ . '/logs'; if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
  $logFile = $logDir . '/prefs.log';
  // Rotate log when it grows too much
  if (function_exists('rotate_log')) { @rotate_log($logFile, 524288, 3); }
  $log = function(string $event, array $data) use ($logFile, $userId) {
    $row = [ 'ts'=>date('c'), 'uid'=>$userId, 'ev'=>$event, 'data'=>$data ];
    @file_put_contents($logFile, json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  };
  $log('settings_set:input', is_array($in) ? $in : ['non_array'=>true]);

  $pdo = db();
  // Schema
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL UNIQUE,
      series_provider VARCHAR(32) NOT NULL DEFAULT 'auto',
      options_provider VARCHAR(32) NOT NULL DEFAULT 'auto',
      data_provider VARCHAR(32) NULL,
      resolutions_json LONGTEXT NULL,
      indicators_json LONGTEXT NULL,
      ai_provider VARCHAR(32) NOT NULL DEFAULT 'auto',
      ai_model VARCHAR(128) NULL,
      data LONGTEXT NULL,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Migración suave: agregar columnas si no existen
  try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN data_provider VARCHAR(32) NULL AFTER options_provider"); } catch (Throwable $e) { /* ya existe */ }
  try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN ai_prompt_ext_conten_file TEXT NULL"); } catch (Throwable $e) { /* ya existe */ }

  // Cargar existentes
  $st0 = $pdo->prepare("SELECT series_provider, options_provider, data_provider, resolutions_json, indicators_json, ai_provider, ai_model, ai_prompt_ext_conten_file, data FROM user_settings WHERE user_id = ? LIMIT 1");
  $st0->execute([$userId]);
  $row0 = $st0->fetch(PDO::FETCH_ASSOC) ?: [];

  // Helper flags
  $has = fn($k) => array_key_exists($k, $in);

  // Normalización + fallback a existentes
  $seriesProv  = strtolower(trim((string)($has('series_provider')  ? $in['series_provider']  : ($row0['series_provider']  ?? 'auto'))));
  $optionsProv = strtolower(trim((string)($has('options_provider') ? $in['options_provider'] : ($row0['options_provider'] ?? 'auto'))));
  $dataProv    = strtolower(trim((string)($has('data_provider')    ? $in['data_provider']    : ($row0['data_provider']    ?? null))));

  $resRaw = $has('resolutions_json') ? $in['resolutions_json'] : ($row0['resolutions_json'] ?? ['daily','60min','15min']);
  if (is_string($resRaw)) { $tmp = json_decode($resRaw, true); $resolutions = is_array($tmp) ? $tmp : []; }
  else $resolutions = is_array($resRaw) ? $resRaw : [];

  $indRaw = $has('indicators_json') ? $in['indicators_json'] : ($row0['indicators_json'] ?? []);
  if (is_string($indRaw)) { $tmp = json_decode($indRaw, true); $indicators = is_array($tmp) ? $tmp : []; }
  else $indicators = is_array($indRaw) ? $indRaw : [];

  $aiProv  = strtolower(trim((string)($has('ai_provider') ? $in['ai_provider'] : ($row0['ai_provider'] ?? 'auto'))));
  $aiModel = trim((string)($has('ai_model')    ? $in['ai_model']    : ($row0['ai_model']    ?? '')));
  $aiPromptExtract = $has('ai_prompt_ext_conten_file') ? $in['ai_prompt_ext_conten_file'] : ($row0['ai_prompt_ext_conten_file'] ?? null);

  // Sanitizar
  $allowedSeries  = ['auto','alphavantage','tiingo','finnhub','polygon'];
  if (!in_array($seriesProv, $allowedSeries, true)) $seriesProv = 'auto';
  $allowedOptions = ['auto','polygon','finnhub'];
  if (!in_array($optionsProv, $allowedOptions, true)) $optionsProv = 'auto';
  if ($dataProv !== null && !in_array($dataProv, $allowedSeries, true)) $dataProv = 'auto';
  $allowedAi      = ['auto','gemini','openai','claude','xai','deepseek'];
  if (!in_array($aiProv, $allowedAi, true)) $aiProv = 'auto';

  // INSERT o UPDATE
  if ($row0) {
    $up = $pdo->prepare("UPDATE user_settings SET series_provider=?, options_provider=?, data_provider=?, resolutions_json=?, indicators_json=?, ai_provider=?, ai_model=?, ai_prompt_ext_conten_file=?, updated_at=NOW() WHERE user_id=?");
    $up->execute([
      $seriesProv,
      $optionsProv,
      $dataProv,
      json_encode($resolutions, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      json_encode($indicators,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      $aiProv,
      $aiModel,
      $aiPromptExtract,
      $userId,
    ]);
  } else {
    $ins = $pdo->prepare("INSERT INTO user_settings (user_id, series_provider, options_provider, data_provider, resolutions_json, indicators_json, ai_provider, ai_model, ai_prompt_ext_conten_file, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
    $ins->execute([
      $userId,
      $seriesProv,
      $optionsProv,
      $dataProv,
      json_encode($resolutions, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      json_encode($indicators,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
      $aiProv,
      $aiModel,
      $aiPromptExtract,
    ]);
  }
  $log('settings_set:upsert', compact('seriesProv','optionsProv','dataProv','aiProv','aiModel'));

  // Extras en columna data (merge)
  $extras = [];
  if (isset($in['options_expiry_rule']))   $extras['options_expiry_rule']   = (string)$in['options_expiry_rule'];
  if (isset($in['options_strike_count']))  $extras['options_strike_count']  = (int)$in['options_strike_count'];
  if (isset($in['atm_price_source']))      $extras['atm_price_source']      = (string)$in['atm_price_source'];
  if (isset($in['tz_offset']))             $extras['tz_offset']             = (string)$in['tz_offset'];
  if (isset($in['net']) && is_array($in['net'])) $extras['net'] = $in['net'];
  if (isset($in['symbol']))  $extras['symbol']  = strtoupper(trim((string)$in['symbol']));
  if (isset($in['amount']))  $extras['amount']  = is_numeric($in['amount']) ? (float)$in['amount'] : null;
  if (isset($in['tp']))      $extras['tp']      = is_numeric($in['tp']) ? (float)$in['tp'] : null;
  if (isset($in['sl']))      $extras['sl']      = is_numeric($in['sl']) ? (float)$in['sl'] : null;
  // Guardar también proveedores básicos en data (comodidad en front)
  $extras['series_provider']  = $seriesProv;
  $extras['options_provider'] = $optionsProv;
  if ($dataProv !== null && $dataProv !== '') $extras['data_provider'] = $dataProv;
  $extras['ai_provider']      = $aiProv;
  $extras['ai_model']         = $aiModel;

  if (!empty($extras)) {
    $curr = [];
    if (!empty($row0['data'])) {
      $tmp = json_decode((string)$row0['data'], true);
      if (is_array($tmp)) $curr = $tmp;
    }
    $merged = array_merge($curr, $extras);
    $up2 = $pdo->prepare("UPDATE user_settings SET data = ?, updated_at = NOW() WHERE user_id = ?");
    $up2->execute([ json_encode($merged, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $userId ]);
    $log('settings_set:data_saved', $merged);
  }

  json_out([
    'ok' => true,
    'mode' => 'upsert-db',
    'saved' => [
      'series_provider'  => $seriesProv,
      'options_provider' => $optionsProv,
      'data_provider'    => $dataProv,
      'resolutions_json' => $resolutions,
      'indicators_json'  => $indicators,
      'ai_provider'      => $aiProv,
      'ai_model'         => $aiModel,
    ],
  ]);

} catch (Throwable $e) {
  $errLog = __DIR__.'/logs/prefs.log';
  if (function_exists('rotate_log')) { @rotate_log($errLog, 524288, 3); }
  @file_put_contents($errLog, json_encode(['ts'=>date('c'),'ev'=>'settings_set:error','detail'=>$e->getMessage()])."\n", FILE_APPEND);
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}
