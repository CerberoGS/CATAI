<?php
// /bolsa/api/analysis_save.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $in = json_input(true);
  if (!is_array($in)) json_error('invalid-json', 400);

  $symbol   = strtoupper(trim((string)($in['symbol'] ?? '')));
  $title    = trim((string)($in['title'] ?? ''));
  $timeframe = trim((string)($in['timeframe'] ?? ''));
  $analysisText = (string)($in['analysis_text'] ?? '');
  $analysisJson = isset($in['analysis_json']) ? $in['analysis_json'] : null;
  $snapshot     = isset($in['snapshot_json']) ? $in['snapshot_json'] : null;
  $userNotes    = (string)($in['user_notes'] ?? '');
  $traded       = (int)(!empty($in['traded']));
  $outcome      = strtolower(trim((string)($in['outcome'] ?? '')));
  $pnl          = isset($in['pnl']) && is_numeric($in['pnl']) ? (float)$in['pnl'] : null;
  $currency     = isset($in['currency']) ? substr(trim((string)$in['currency']), 0, 8) : null;
  $attachments  = [];
  if (isset($in['attachments']) && is_array($in['attachments'])) $attachments = $in['attachments'];
  elseif (isset($in['attachments_urls']) && is_array($in['attachments_urls'])) {
    foreach ($in['attachments_urls'] as $uurl) { $attachments[] = ['url'=>$uurl]; }
  }

  if ($symbol === '') json_error('symbol-required', 400);
  if ($outcome !== '' && !in_array($outcome, ['pos','neg','neutro'], true)) $outcome = '';

  $pdo = db();
  // Ensure tables exist
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    symbol VARCHAR(32) NOT NULL,
    timeframe VARCHAR(32) NULL,
    title VARCHAR(200) NULL,
    analysis_text LONGTEXT NULL,
    analysis_json LONGTEXT NULL,
    snapshot_json LONGTEXT NULL,
    user_notes LONGTEXT NULL,
    traded TINYINT(1) NOT NULL DEFAULT 0,
    outcome VARCHAR(8) NULL,
    pnl DECIMAL(14,2) NULL,
    currency VARCHAR(8) NULL,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_user_symbol (user_id, symbol)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS user_analysis_attachment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    analysis_id INT NOT NULL,
    url VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    size INT NULL,
    caption VARCHAR(200) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_analysis (user_id, analysis_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $ins = $pdo->prepare("INSERT INTO user_analysis (user_id, symbol, timeframe, title, analysis_text, analysis_json, snapshot_json, user_notes, traded, outcome, pnl, currency) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
  $ins->execute([
    $userId,
    $symbol,
    ($timeframe !== '' ? $timeframe : null),
    ($title !== '' ? $title : null),
    ($analysisText !== '' ? $analysisText : null),
    ($analysisJson !== null ? json_encode($analysisJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null),
    ($snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null),
    ($userNotes !== '' ? $userNotes : null),
    $traded,
    ($outcome !== '' ? $outcome : null),
    $pnl,
    ($currency !== null && $currency !== '' ? $currency : null),
  ]);
  $analysisId = (int)$pdo->lastInsertId();

  if ($attachments) {
    $ai = $pdo->prepare("INSERT INTO user_analysis_attachment (user_id, analysis_id, url, mime, size, caption) VALUES (?,?,?,?,?,?)");
    foreach ($attachments as $att) {
      $url = isset($att['url']) ? trim((string)$att['url']) : '';
      if ($url === '') continue;
      $mime = isset($att['mime']) ? substr(trim((string)$att['mime']), 0, 100) : null;
      $size = isset($att['size']) && is_numeric($att['size']) ? (int)$att['size'] : null;
      $caption = isset($att['caption']) ? substr(trim((string)$att['caption']), 0, 200) : null;
      $ai->execute([$userId, $analysisId, $url, $mime, $size, $caption]);
    }
  }

  json_out(['ok'=>true, 'id'=>$analysisId]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

