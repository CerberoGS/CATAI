<?php
// /bolsa/api/analysis_delete.php
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
  // Fetch attachments first
  $att = $pdo->prepare("SELECT id, url FROM user_analysis_attachment WHERE user_id = ? AND analysis_id = ?");
  $att->execute([$userId, $id]);
  $attachments = $att->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Delete DB rows in a transaction
  db_tx(function(PDO $pdo) use ($userId, $id) {
    $pdo->prepare('DELETE FROM user_analysis_attachment WHERE user_id=? AND analysis_id=?')->execute([$userId, $id]);
    $pdo->prepare('DELETE FROM user_analysis WHERE user_id=? AND id=?')->execute([$userId, $id]);
  });

  // Try to delete files under static/uploads/<userId>/ safely
  foreach ($attachments as $a) {
    $url = (string)($a['url'] ?? '');
    if ($url === '') continue;
    // Expected format: /bolsa/static/uploads/{userId}/{name}
    $parts = explode('/', trim($url, '/'));
    $n = count($parts);
    if ($n >= 4 && $parts[$n-4] === 'bolsa' && $parts[$n-3] === 'static' && $parts[$n-2] === 'uploads' && ((int)$parts[$n-1-1] === $userId || $parts[$n-2] === (string)$userId)) {
      // Construct path based on repo structure
      $name = $parts[$n-1];
      $dir = dirname(__DIR__) . '/static/uploads/' . $userId;
      $path = $dir . '/' . basename($name);
      if (is_file($path)) @unlink($path);
    } else {
      // Alternative: if matches uploads/{userId}/{name}
      $pos = array_search('uploads', $parts, true);
      if ($pos !== false && isset($parts[$pos+1]) && (int)$parts[$pos+1] === $userId && isset($parts[$pos+2])) {
        $path = dirname(__DIR__) . '/static/uploads/' . $userId . '/' . basename($parts[$pos+2]);
        if (is_file($path)) @unlink($path);
      }
    }
  }

  json_out(['ok'=>true]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

