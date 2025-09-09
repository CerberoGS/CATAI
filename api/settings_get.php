<?php
// /bolsa/api/settings_get.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)$u['id'];

  // Logger
  $logDir = __DIR__ . '/logs';
  if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
  $logFile = $logDir . '/prefs.log';
  $log = function(string $event, array $data) use ($logFile, $userId) {
    $row = [ 'ts'=>date('c'), 'uid'=>$userId, 'ev'=>$event, 'data'=>$data ];
    @file_put_contents($logFile, json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
  };

  $pdo = db();

  // Crear tabla si no existe (no altera esquemas ya creados)
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
  // Migración suave de columna data_provider
  try { $pdo->exec("ALTER TABLE user_settings ADD COLUMN data_provider VARCHAR(32) NULL AFTER options_provider"); } catch (Throwable $e) { }

  $row = null;
  $mode = 'db-new-schema';
  try {
    $st = $pdo->prepare("SELECT series_provider, options_provider, data_provider, resolutions_json, indicators_json, ai_provider, ai_model, data
                         FROM user_settings
                         WHERE user_id = ?
                         ORDER BY updated_at DESC, id DESC
                         LIMIT 1");
    $st->execute([$userId]);
    $row = $st->fetch();
  } catch (Throwable $e) {
    $row = null;
  }

  $settings = null;
  if ($row) {
    $log('settings_get:row', $row);
    $resolutions = [];
    if (isset($row['resolutions_json'])) {
      $tmp = json_decode((string)$row['resolutions_json'], true);
      if (is_array($tmp)) $resolutions = $tmp;
    }
    $indicators = [];
    if (isset($row['indicators_json'])) {
      $tmp = json_decode((string)$row['indicators_json'], true);
      if (is_array($tmp)) $indicators = $tmp;
    }
    $settings = [
      'series_provider'  => (string)($row['series_provider']  ?? 'auto'),
      'options_provider' => (string)($row['options_provider'] ?? 'auto'),
      'data_provider'    => isset($row['data_provider']) && $row['data_provider'] !== null ? (string)$row['data_provider'] : null,
      'resolutions_json' => $resolutions,
      'indicators_json'  => $indicators,
      'ai_provider'      => (string)($row['ai_provider'] ?? 'auto'),
      'ai_model'         => (string)($row['ai_model']    ?? ''),
    ];
    // Merge extras desde columna data si existe
    if (isset($row['data']) && is_string($row['data']) && $row['data'] !== '') {
      $tmp = json_decode((string)$row['data'], true);
      if (is_array($tmp)) $settings = array_merge($settings, $tmp);
    }
  } else {
    // Intentar esquemas antiguos
    $mode = 'db-legacy';
    try {
      // Variante 1: columna JSON 'data'
      $st = $pdo->prepare("SELECT data FROM user_settings WHERE user_id = ? LIMIT 1");
      $st->execute([$userId]);
      $r = $st->fetch();
      if ($r && isset($r['data']) && is_string($r['data']) && $r['data'] !== '') {
        $d = json_decode((string)$r['data'], true) ?: [];
        if (is_array($d)) $settings = $d;
      }
    } catch (Throwable $e) { /* ignore */ }
    if ($settings === null) {
      try {
        // Variante 2: columna LONGTEXT 'settings' (JSON)
        $st = $pdo->prepare("SELECT settings FROM user_settings WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $r = $st->fetch();
        if ($r && isset($r['settings']) && is_string($r['settings']) && $r['settings'] !== '') {
          $d = json_decode((string)$r['settings'], true) ?: [];
          if (is_array($d)) $settings = $d;
        }
      } catch (Throwable $e) { /* ignore */ }
    }
  }

  // Sin fallback a archivo: solo DB. Si no hay datos, devolvemos defaults.

  if ($settings !== null) {
    $resp = [ 'ok'=>true, 'source'=>$mode, 'settings'=>$settings ];
    $log('settings_get:response', $resp);
    json_out($resp);
  }

  // Defaults si no hay datos para este usuario
  $defaults = [
    'ok' => true,
    'source' => 'defaults',
    'settings' => [
      'series_provider'  => 'auto',
      'options_provider' => 'auto',
      'resolutions_json' => ['daily','60min','15min'],
      'indicators_json'  => [
        'daily'  => ['rsi14'=>true,'sma20'=>true,'ema20'=>true,'ema40'=>true,'ema100'=>true,'ema200'=>true],
        '60min'  => ['rsi14'=>true,'sma20'=>true,'ema20'=>true],
        '15min'  => ['rsi14'=>true,'sma20'=>true,'ema20'=>true],
      ],
      'ai_provider' => 'auto',
      'ai_model'    => 'gemini-2.0-flash',
    ],
  ];
  $log('settings_get:defaults', $defaults);
  json_out($defaults);

} catch (Throwable $e) {
  @file_put_contents(__DIR__ . '/logs/prefs.log', json_encode(['ts'=>date('c'),'ev'=>'settings_get:error','detail'=>$e->getMessage()]) . "\n", FILE_APPEND);
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

