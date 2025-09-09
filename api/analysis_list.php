<?php
// /bolsa/api/analysis_list.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_error('unauthorized', 401);

  $limit  = isset($_GET['limit'])  ? max(1, min(100, (int)$_GET['limit'])) : 20;
  $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
  $symbol = isset($_GET['symbol']) ? strtoupper(trim((string)$_GET['symbol'])) : '';
  $outcome = isset($_GET['outcome']) ? strtolower(trim((string)$_GET['outcome'])) : '';
  $traded = isset($_GET['traded']) ? (int)$_GET['traded'] : null; // 0/1
  $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
  $to   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : '';

  $where = ['user_id = ?'];
  $params = [$userId];
  if ($symbol !== '') { $where[] = 'symbol = ?'; $params[] = $symbol; }
  if ($outcome !== '' && in_array($outcome, ['pos','neg','neutro'], true)) { $where[] = 'outcome = ?'; $params[] = $outcome; }
  if ($traded !== null) { $where[] = 'traded = ?'; $params[] = $traded; }
  if ($q !== '') { $where[] = '(title LIKE ? OR analysis_text LIKE ? OR user_notes LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
  if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; }
  if ($to   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'created_at <= ?'; $params[] = $to   . ' 23:59:59'; }

  $sql = 'SELECT id, created_at, updated_at, symbol, timeframe, title, traded, outcome FROM user_analysis WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
  $params2 = array_merge($params, [$limit, $offset]);
  $pdo = db();
  $st = $pdo->prepare($sql);
  $st->execute($params2);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Count total
  $st2 = $pdo->prepare('SELECT COUNT(*) as c FROM user_analysis WHERE ' . implode(' AND ', $where));
  $st2->execute($params);
  $total = (int)($st2->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

  json_out(['ok'=>true, 'items'=>$rows, 'total'=>$total, 'limit'=>$limit, 'offset'=>$offset]);
} catch (Throwable $e) {
  json_out(['error'=>'internal_exception','detail'=>$e->getMessage()], 500);
}

