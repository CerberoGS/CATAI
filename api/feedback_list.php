<?php
// /bolsa/api/feedback_list.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $limit  = isset($_GET['limit'])  ? max(1, min(100, (int)$_GET['limit'])) : 20;
  $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
  $status   = isset($_GET['status'])   ? strtolower(trim((string)$_GET['status']))   : '';
  $type     = isset($_GET['type'])     ? strtolower(trim((string)$_GET['type']))     : '';
  $severity = isset($_GET['severity']) ? strtolower(trim((string)$_GET['severity'])) : '';
  $module   = isset($_GET['module'])   ? strtolower(trim((string)$_GET['module']))   : '';
  $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
  $to   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

  $isAdmin = false;
  try {
    $role = (string)($u['role'] ?? '');
    if ($role === 'admin') $isAdmin = true;
    if (!$isAdmin && !empty($u['roles']) && is_array($u['roles'])) { $isAdmin = in_array('admin', $u['roles'], true); }
    if (!$isAdmin && !empty($u['is_admin'])) { $isAdmin = (bool)$u['is_admin']; }
  } catch (\Throwable $e) {}

  $where = [];
  $params = [];
  if (!$isAdmin) { $where[] = 'user_id = ?'; $params[] = $userId; }
  if ($status !== '')   { $where[] = 'status = ?';   $params[] = $status; }
  if ($type !== '')     { $where[] = 'type = ?';     $params[] = $type; }
  if ($severity !== '') { $where[] = 'severity = ?'; $params[] = $severity; }
  if ($module !== '')   { $where[] = 'module = ?';   $params[] = $module; }
  if ($q !== '') { $where[] = '(title LIKE ? OR description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
  if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; }
  if ($to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'created_at <= ?'; $params[] = $to   . ' 23:59:59'; }

  $columns = 'id, created_at, updated_at, user_id, type, severity, module, title, status';
  $sql = 'SELECT ' . $columns . ' FROM user_feedback ' . (count($where)? ('WHERE ' . implode(' AND ', $where)) : '') . ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
  $params2 = array_merge($params, [$limit, $offset]);
  $pdo = db();
  $st = $pdo->prepare($sql);
  $st->execute($params2);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $countSql = 'SELECT COUNT(*) as c FROM user_feedback ' . (count($where)? ('WHERE ' . implode(' AND ', $where)) : '');
  $st2 = $pdo->prepare($countSql);
  $st2->execute($params);
  $total = (int)($st2->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

  json_out(['ok'=>true,'items'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}
