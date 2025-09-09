<?php
// /bolsa/api/analysis_attachment_delete.php
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
  $st = $pdo->prepare('SELECT analysis_id, url FROM user_analysis_attachment WHERE id=? AND user_id=? LIMIT 1');
  $st->execute([$id, $userId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) json_error('not-found', 404);

  // Delete DB row
  $pdo->prepare('DELETE FROM user_analysis_attachment WHERE id=? AND user_id=?')->execute([$id, $userId]);

  // Try to delete file from disk if under uploads/userId
  $url = (string)($row['url'] ?? '');
  if ($url !== '') {
    $parts = explode('/', trim($url, '/'));
    $pos = array_search('uploads', $parts, true);
    if ($pos !== false && isset($parts[$pos+1]) && (int)$parts[$pos+1] === $userId && isset($parts[$pos+2])) {
      $path = dirname(__DIR__) . '/static/uploads/' . $userId . '/' . basename($parts[$pos+2]);
      if (is_file($path)) @unlink($path);
    }
  }

  json_out(['ok'=>true]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

