<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
apply_cors();

try {
  $user = require_user();
  $pdo = db();

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $stmt = $pdo->prepare('SELECT preferences_json FROM user_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $prefs = $row && !empty($row['preferences_json']) ? json_decode((string)$row['preferences_json'], true) : [];
    $ai = is_array($prefs) && isset($prefs['ai']) ? $prefs['ai'] : [];
    return json_out(['ok'=>true, 'profile'=>[
      'provider' => $ai['provider'] ?? 'auto',
      'model'    => $ai['model'] ?? '',
      'lang'     => $ai['lang'] ?? 'es-US',
      'tone'     => $ai['tone'] ?? 'conciso',
      'risk'     => $ai['risk'] ?? 'moderado',
    ]]);
  }

  // POST guardar
  $raw = file_get_contents('php://input') ?: '{}';
  $data = json_decode($raw, true) ?: [];
  $provider = (string)($data['provider'] ?? 'auto');
  $model    = (string)($data['model'] ?? '');
  $lang     = (string)($data['lang'] ?? 'es-US');
  $tone     = (string)($data['tone'] ?? 'conciso');
  $risk     = (string)($data['risk'] ?? 'moderado');

  $pdo->beginTransaction();
  $stmt = $pdo->prepare('SELECT preferences_json FROM user_settings WHERE user_id = ? FOR UPDATE');
  $stmt->execute([$user['id']]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  $prefs = $row && !empty($row['preferences_json']) ? json_decode((string)$row['preferences_json'], true) : [];
  if (!is_array($prefs)) $prefs = [];
  $prefs['ai'] = [ 'provider'=>$provider, 'model'=>$model, 'lang'=>$lang, 'tone'=>$tone, 'risk'=>$risk ];
  $json = json_encode($prefs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

  if ($row) {
    $stmt = $pdo->prepare('UPDATE user_settings SET preferences_json = ?, updated_at = NOW() WHERE user_id = ?');
    $stmt->execute([$json, $user['id']]);
  } else {
    $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, preferences_json, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
    $stmt->execute([$user['id'], $json]);
  }
  $pdo->commit();

  return json_out(['ok'=>true]);
} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
  return json_out(['ok'=>false, 'error'=>$e->getMessage()], 500);
}


