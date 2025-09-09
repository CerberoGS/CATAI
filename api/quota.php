<?php
// quota.php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function today_range_utc() {
  $now = new DateTime('now', new DateTimeZone('UTC'));
  $start = new DateTime($now->format('Y-m-d').' 00:00:00', new DateTimeZone('UTC'));
  $end = clone $start; $end->modify('+1 day');
  return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function quota_check_and_log($userId, $endpoint, $cost=1) {
  $pdo = db();
  [$start, $end] = today_range_utc();

  // usage hoy
  $st = $pdo->prepare("SELECT endpoint, SUM(cost_units) AS used
                        FROM usage_log
                        WHERE user_id=? AND created_at>=? AND created_at<?
                        GROUP BY endpoint");
  $st->execute([$userId, $start, $end]);
  $usage = ['time_series'=>0,'options'=>0,'ai'=>0];
  foreach ($st as $r) $usage[$r['endpoint']] = (int)$r['used'];

  // cuotas
  $q = $pdo->prepare("SELECT * FROM user_quotas WHERE user_id=?");
  $q->execute([$userId]);
  $quotas = $q->fetch();
  if (!$quotas) {
    $pdo->prepare("INSERT INTO user_quotas (user_id) VALUES (?)")->execute([$userId]);
    $q->execute([$userId]); $quotas = $q->fetch();
  }
  $maxMap = [
    'time_series' => (int)$quotas['max_timeseries_per_day'],
    'options'     => (int)$quotas['max_options_per_day'],
    'ai'          => (int)$quotas['max_ai_per_day'],
  ];
  if ($usage[$endpoint] + $cost > $maxMap[$endpoint]) {
    json_out(['error'=>'quota_exceeded','endpoint'=>$endpoint,'used'=>$usage[$endpoint],'max'=>$maxMap[$endpoint]], 429);
  }

  // log
  $pdo->prepare("INSERT INTO usage_log (user_id, endpoint, cost_units) VALUES (?,?,?)")
      ->execute([$userId, $endpoint, $cost]);
}
