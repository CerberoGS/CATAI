<?php
// /bolsa/api/feedback_update.php
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
  if (isset($in['status'])) { $fields[] = 'status = ?'; $vals[] = strtolower(trim((string)$in['status'])); }
  if (isset($in['title']))  { $fields[] = 'title = ?';  $vals[] = (string)$in['title'] !== '' ? substr((string)$in['title'],0,200) : null; }
  if (isset($in['description'])) { $fields[] = 'description = ?'; $vals[] = (string)$in['description'] !== '' ? (string)$in['description'] : null; }
  if (isset($in['diagnostics_json'])) { $fields[] = 'diagnostics_json = ?'; $vals[] = $in['diagnostics_json'] !== null ? json_encode($in['diagnostics_json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null; }

  if (empty($fields) && empty($in['attachments'])) json_error('no-fields', 400);

  $pdo = db();
  $isAdmin = false;
  try {
    $role = (string)($u['role'] ?? '');
    if ($role === 'admin') $isAdmin = true;
    if (!$isAdmin && !empty($u['roles']) && is_array($u['roles'])) { $isAdmin = in_array('admin', $u['roles'], true); }
    if (!$isAdmin && !empty($u['is_admin'])) { $isAdmin = (bool)$u['is_admin']; }
  } catch (\Throwable $e) {}

  if (!empty($fields)) {
    if ($isAdmin) {
      $sql = 'UPDATE user_feedback SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?';
      $vals[] = $id;
    } else {
      $sql = 'UPDATE user_feedback SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ? AND user_id = ?';
      $vals[] = $id; $vals[] = $userId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($vals);
  }

  if (isset($in['attachments']) && is_array($in['attachments'])) {
    $ai = $pdo->prepare('INSERT INTO user_feedback_attachment (user_id, feedback_id, url, mime, size, caption) VALUES (?,?,?,?,?,?)');
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
