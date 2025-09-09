<?php
// /bolsa/api/analysis_update.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $in = json_input(true);
  if (!is_array($in)) json_error('invalid-json', 400);
  $id = isset($in['id']) ? (int)$in['id'] : 0;
  if ($id <= 0) json_error('id-required', 400);

  $fields = [];
  $vals = [];

  if (isset($in['title']))      { $fields[] = 'title = ?';      $vals[] = (string)$in['title'] !== '' ? substr((string)$in['title'],0,200) : null; }
  if (isset($in['user_notes']))  { $fields[] = 'user_notes = ?';  $vals[] = (string)$in['user_notes'] !== '' ? (string)$in['user_notes'] : null; }
  if (isset($in['traded']))      { $fields[] = 'traded = ?';      $vals[] = !empty($in['traded']) ? 1 : 0; }
  if (isset($in['outcome']))     { $fields[] = 'outcome = ?';     $o = strtolower(trim((string)$in['outcome'])); $vals[] = in_array($o, ['pos','neg','neutro'], true) ? $o : null; }
  if (isset($in['pnl']))         { $fields[] = 'pnl = ?';         $vals[] = is_numeric($in['pnl']) ? (float)$in['pnl'] : null; }
  if (isset($in['currency']))    { $fields[] = 'currency = ?';    $c = substr(trim((string)$in['currency']),0,8); $vals[] = $c !== '' ? $c : null; }

  if (isset($in['analysis_text'])) { $fields[] = 'analysis_text = ?'; $vals[] = (string)$in['analysis_text'] !== '' ? (string)$in['analysis_text'] : null; }
  if (isset($in['analysis_json'])) { $fields[] = 'analysis_json = ?'; $vals[] = $in['analysis_json'] !== null ? json_encode($in['analysis_json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null; }
  if (isset($in['snapshot_json'])) { $fields[] = 'snapshot_json = ?'; $vals[] = $in['snapshot_json'] !== null ? json_encode($in['snapshot_json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null; }

  if (empty($fields)) json_error('no-fields', 400);

  $pdo = db();
  $sql = 'UPDATE user_analysis SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ? AND user_id = ?';
  $vals[] = $id; $vals[] = $userId;
  $st = $pdo->prepare($sql);
  $st->execute($vals);

  // Adjuntos opcionales (suma)
  if (isset($in['attachments']) && is_array($in['attachments'])) {
    $ai = $pdo->prepare("INSERT INTO user_analysis_attachment (user_id, analysis_id, url, mime, size, caption) VALUES (?,?,?,?,?,?)");
    foreach ($in['attachments'] as $att) {
      $url = isset($att['url']) ? trim((string)$att['url']) : '';
      if ($url === '') continue;
      $mime = isset($att['mime']) ? substr(trim((string)$att['mime']), 0, 100) : null;
      $size = isset($att['size']) && is_numeric($att['size']) ? (int)$att['size'] : null;
      $caption = isset($att['caption']) ? substr(trim((string)$att['caption']), 0, 200) : null;
      $ai->execute([$userId, $id, $url, $mime, $size, $caption]);
    }
  }

  json_out(['ok'=>true]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

