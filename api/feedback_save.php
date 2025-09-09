<?php
// /bolsa/api/feedback_save.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $in = json_input(true);
  if (!is_array($in)) json_error('invalid-json', 400);

  $type = strtolower(trim((string)($in['type'] ?? '')));
  $severity = strtolower(trim((string)($in['severity'] ?? '')));
  $module = strtolower(trim((string)($in['module'] ?? 'otro')));
  $title = trim((string)($in['title'] ?? ''));
  $description = (string)($in['description'] ?? '');
  $diagnostics = isset($in['diagnostics_json']) ? $in['diagnostics_json'] : null;
  $attachments = isset($in['attachments']) && is_array($in['attachments']) ? $in['attachments'] : [];

  $allowedTypes = ['bug','mejora','ux','idea'];
  if (!in_array($type, $allowedTypes, true)) $type = 'idea';
  $allowedSeverity = ['blocker','mayor','menor','sugerencia'];
  if (!in_array($severity, $allowedSeverity, true)) $severity = 'sugerencia';

  if ($title === '' && $description === '') json_error('missing-content', 400, 'title or description required');

  $pdo = db();
  // Create tables if needed
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    type VARCHAR(16) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    module VARCHAR(32) NOT NULL,
    title VARCHAR(200) NULL,
    description LONGTEXT NULL,
    diagnostics_json LONGTEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'nuevo',
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_status (status),
    INDEX idx_module (module)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS user_feedback_attachment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    feedback_id INT NOT NULL,
    url VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NULL,
    size INT NULL,
    caption VARCHAR(200) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_feedback (user_id, feedback_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $ins = $pdo->prepare("INSERT INTO user_feedback (user_id, type, severity, module, title, description, diagnostics_json) VALUES (?,?,?,?,?,?,?)");
  $ins->execute([
    $userId,
    $type,
    $severity,
    $module !== '' ? $module : 'otro',
    $title !== '' ? $title : null,
    $description !== '' ? $description : null,
    $diagnostics !== null ? json_encode($diagnostics, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
  ]);
  $fid = (int)$pdo->lastInsertId();

  if ($attachments) {
    $ai = $pdo->prepare("INSERT INTO user_feedback_attachment (user_id, feedback_id, url, mime, size, caption) VALUES (?,?,?,?,?,?)");
    foreach ($attachments as $att) {
      $url = isset($att['url']) ? trim((string)$att['url']) : '';
      if ($url === '') continue;
      $mime = isset($att['mime']) ? substr(trim((string)$att['mime']), 0, 100) : null;
      $size = isset($att['size']) && is_numeric($att['size']) ? (int)$att['size'] : null;
      $caption = isset($att['caption']) ? substr(trim((string)$att['caption']), 0, 200) : null;
      $ai->execute([$userId, $fid, $url, $mime, $size, $caption]);
    }
  }

  // Optional webhook
  try {
    $cfg = $GLOBALS['__APP_CONFIG'] ?? [];
    $hook = (string)($cfg['FEEDBACK_WEBHOOK_URL'] ?? '');
    if ($hook !== '') {
      $payload = [
        'title' => $title,
        'type' => $type,
        'severity' => $severity,
        'module' => $module,
        'user' => ($u['email'] ?? $u['sub'] ?? 'unknown'),
        'id' => $fid,
        'created_at' => date('c'),
      ];
      if (function_exists('http_post_json')) {
        http_post_json($hook, $payload, [], 5);
      }
    }
  } catch (\Throwable $e) { /* ignore webhook errors */ }

  json_out(['ok'=>true, 'id'=>$fid]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

