<?php
// /bolsa/api/feedback_upload.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_error('method-not-allowed', 405);
  if (empty($_FILES) || !isset($_FILES['file'])) json_error('file-required', 400);

  $f = $_FILES['file'];
  if (!empty($f['error'])) json_error('upload-error', 400, (string)$f['error']);

  $size = (int)$f['size'];
  $maxBytes = 5 * 1024 * 1024; // 5MB
  if ($size <= 0 || $size > $maxBytes) json_error('file-too-large', 400, 'max 5MB');

  $tmp = (string)$f['tmp_name'];
  $mime = (string)($f['type'] ?? '');
  $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg','image/webp'=>'webp'];
  $ext = $allowed[$mime] ?? null;
  if ($ext === null) { $ext = 'png'; }

  $baseDir = dirname(__DIR__) . '/static/feedback/' . $userId;
  if (!is_dir($baseDir)) @mkdir($baseDir, 0775, true);

  // secure uploads folder
  $hta = dirname(__DIR__) . '/static/feedback/.htaccess';
  if (!is_file($hta)) {
    @file_put_contents($hta, "Options -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\nphp_flag engine off\nHeader set X-Content-Type-Options nosniff\n");
  }

  $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
  $dest = $baseDir . '/' . $name;
  if (!@move_uploaded_file($tmp, $dest)) json_error('move-failed', 500);

  $url = '/bolsa/static/feedback/' . $userId . '/' . $name;
  json_out(['ok'=>true, 'url'=>$url, 'mime'=>$mime, 'size'=>$size]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

