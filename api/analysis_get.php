<?php
// /bolsa/api/analysis_get.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($id <= 0) json_error('id-required', 400);

  $pdo = db();
  $st = $pdo->prepare("SELECT * FROM user_analysis WHERE id = ? AND user_id = ? LIMIT 1");
  $st->execute([$id, $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_error('not-found', 404);

  // Decode JSON fields
  foreach (['analysis_json','snapshot_json'] as $jf) {
    if (isset($row[$jf]) && is_string($row[$jf]) && $row[$jf] !== '') {
      $tmp = json_decode((string)$row[$jf], true);
      if (is_array($tmp) || is_object($tmp)) $row[$jf] = $tmp;
    }
  }

  $att = $pdo->prepare("SELECT id, url, mime, size, caption, created_at FROM user_analysis_attachment WHERE user_id = ? AND analysis_id = ? ORDER BY id ASC");
  $att->execute([$userId, $id]);
  $attachments = $att->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_out(['ok'=>true, 'analysis'=>$row, 'attachments'=>$attachments]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

