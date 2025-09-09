<?php
// /bolsa/api/feedback_get.php
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
  $isAdmin = false;
  try {
    $role = (string)($u['role'] ?? '');
    if ($role === 'admin') $isAdmin = true;
    if (!$isAdmin && !empty($u['roles']) && is_array($u['roles'])) { $isAdmin = in_array('admin', $u['roles'], true); }
    if (!$isAdmin && !empty($u['is_admin'])) { $isAdmin = (bool)$u['is_admin']; }
  } catch (\Throwable $e) {}

  if ($isAdmin) {
    $st = $pdo->prepare('SELECT * FROM user_feedback WHERE id = ? LIMIT 1');
    $st->execute([$id]);
  } else {
    $st = $pdo->prepare('SELECT * FROM user_feedback WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$id, $userId]);
  }
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_error('not-found', 404);

  if (isset($row['diagnostics_json']) && is_string($row['diagnostics_json']) && $row['diagnostics_json'] !== '') {
    $tmp = json_decode((string)$row['diagnostics_json'], true);
    if (is_array($tmp) || is_object($tmp)) $row['diagnostics_json'] = $tmp;
  }

  if ($isAdmin) {
    $att = $pdo->prepare('SELECT id, url, mime, size, caption, created_at FROM user_feedback_attachment WHERE feedback_id = ? ORDER BY id ASC');
    $att->execute([$id]);
  } else {
    $att = $pdo->prepare('SELECT id, url, mime, size, caption, created_at FROM user_feedback_attachment WHERE user_id = ? AND feedback_id = ? ORDER BY id ASC');
    $att->execute([$userId, $id]);
  }
  $attachments = $att->fetchAll(PDO::FETCH_ASSOC) ?: [];

  json_out(['ok'=>true, 'feedback'=>$row, 'attachments'=>$attachments]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}
