<?php
// /bolsa/api/feedback_attachment_delete.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $in = json_input(true);
  $id = isset($in['id']) ? (int)$in['id'] : 0;
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
    $st = $pdo->prepare('SELECT feedback_id, url, user_id FROM user_feedback_attachment WHERE id=? LIMIT 1');
    $st->execute([$id]);
  } else {
    $st = $pdo->prepare('SELECT feedback_id, url, user_id FROM user_feedback_attachment WHERE id=? AND user_id=? LIMIT 1');
    $st->execute([$id, $userId]);
  }
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_error('not-found', 404);

  if ($isAdmin) {
    $pdo->prepare('DELETE FROM user_feedback_attachment WHERE id=?')->execute([$id]);
  } else {
    $pdo->prepare('DELETE FROM user_feedback_attachment WHERE id=? AND user_id=?')->execute([$id, $userId]);
  }

  $url = (string)($row['url'] ?? '');
  if ($url !== '') {
    $parts = explode('/', trim($url, '/'));
    $pos = array_search('feedback', $parts, true);
    $ownerId = (int)($row['user_id'] ?? 0) ?: $userId;
    if ($pos !== false && isset($parts[$pos+1]) && (int)$parts[$pos+1] === $ownerId && isset($parts[$pos+2])) {
      $path = dirname(__DIR__) . '/static/feedback/' . $ownerId . '/' . basename($parts[$pos+2]);
      if (is_file($path)) @unlink($path);
    }
  }

  json_out(['ok'=>true]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}
