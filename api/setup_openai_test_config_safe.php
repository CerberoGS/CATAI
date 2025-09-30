<?php
// /catai/api/setup_openai_test_config_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $pdo = db();
  
  // Configuración de prueba para OpenAI
  $openaiTestConfig = [
    'method' => 'GET',
    'headers' => [
      ['name' => 'Authorization', 'value' => 'Bearer {{API_KEY}}']
    ],
    'url_override' => 'https://api.openai.com/v1/models',
    'expected_status' => 200,
    'ok_json_path' => 'object',
    'ok_json_expected' => 'list'
  ];
  
  // Buscar proveedor OpenAI
  $stmt = $pdo->prepare("SELECT id, name, config_json FROM ai_providers WHERE slug = 'openai' OR name LIKE '%OpenAI%' LIMIT 1");
  $stmt->execute();
  $provider = $stmt->fetch(PDO::FETCH_ASSOC);
  
  if (!$provider) {
    json_out(['error' => 'provider-not-found', 'message' => 'Proveedor OpenAI no encontrado'], 404);
  }
  
  $currentConfig = json_decode($provider['config_json'] ?? '{}', true);
  $currentConfig['test'] = $openaiTestConfig;
  
  // Actualizar configuración
  $stmt = $pdo->prepare("UPDATE ai_providers SET config_json = ? WHERE id = ?");
  $success = $stmt->execute([json_encode($currentConfig), $provider['id']]);
  
  if ($success) {
    error_log("✅ Configuración de prueba agregada para OpenAI (ID: {$provider['id']})");
    json_out([
      'ok' => true,
      'message' => 'Configuración de prueba agregada para OpenAI',
      'provider_id' => $provider['id'],
      'provider_name' => $provider['name'],
      'test_config' => $openaiTestConfig
    ]);
  } else {
    json_out(['error' => 'update-failed', 'message' => 'Error actualizando configuración'], 500);
  }
  
} catch (Exception $e) {
  error_log("Error en setup_openai_test_config_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => $e->getMessage()], 500);
}
