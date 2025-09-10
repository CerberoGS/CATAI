<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');
apply_cors();

try {
  $user = require_user();
  $uploadMax = ini_get('upload_max_filesize');
  $postMax   = ini_get('post_max_size');
  $memLimit  = ini_get('memory_limit');
  $providers = [
    'gemini' => !!cfg('GEMINI_API_KEY') || true,
    'openai' => !!cfg('OPENAI_API_KEY') || true,
    'xai'    => !!cfg('XAI_API_KEY') || true,
    'claude' => !!cfg('ANTHROPIC_API_KEY') || true,
    'deepseek' => !!cfg('DEEPSEEK_API_KEY') || true,
  ];
  return json_out([
    'ok'=>true,
    'upload_max_filesize'=>$uploadMax,
    'post_max_size'=>$postMax,
    'memory_limit'=>$memLimit,
    'providers'=>$providers,
  ]);
} catch (Throwable $e) {
  return json_out(['ok'=>false, 'error'=>$e->getMessage()], 500);
}


