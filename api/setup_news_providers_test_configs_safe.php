<?php
// /catai/api/setup_news_providers_test_configs_safe.php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
  $u = require_user();
  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) json_out(['error'=>'invalid-user'], 401);

  $pdo = db();
  
  // Configuraciones de prueba para proveedores de noticias
  $testConfigs = [
    'newsapi' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://newsapi.org/v2/top-headlines?country=us&pageSize=1&apiKey={{API_KEY}}',
        'expected_status' => 200,
        'ok_json_path' => 'status',
        'ok_json_expected' => 'ok'
      ]
    ],
    'gnews' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://gnews.io/api/v4/top-headlines?token={{API_KEY}}&lang=en&max=1',
        'expected_status' => 200,
        'ok_json_path' => 'totalArticles',
        'ok_json_expected' => 'number'
      ]
    ],
    'benzinga' => [
      'test' => [
        'method' => 'GET',
        'headers' => [
          ['name' => 'Authorization', 'value' => 'Bearer {{API_KEY}}']
        ],
        'url_override' => 'https://api.benzinga.com/api/v2.1/news?token={{API_KEY}}&pageSize=1',
        'expected_status' => 200,
        'ok_json_path' => 'news',
        'ok_json_expected' => 'array'
      ]
    ],
    'cryptopanic' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://cryptopanic.com/api/v1/posts/?auth_token={{API_KEY}}&public=true&currencies=BTC&page=1',
        'expected_status' => 200,
        'ok_json_path' => 'results',
        'ok_json_expected' => 'array'
      ]
    ],
    'finviz' => [
      'test' => [
        'method' => 'GET',
        'url_override' => 'https://finviz.com/api/v1/news?token={{API_KEY}}&limit=1',
        'expected_status' => 200,
        'ok_json_path' => 'data',
        'ok_json_expected' => 'array'
      ]
    ]
  ];
  
  $results = [];
  $updated = 0;
  $errors = [];
  
  foreach ($testConfigs as $slug => $config) {
    try {
      // Buscar el proveedor por slug
      $stmt = $pdo->prepare('SELECT id, slug, label FROM news_providers WHERE slug = ?');
      $stmt->execute([$slug]);
      $provider = $stmt->fetch(PDO::FETCH_ASSOC);
      
      if (!$provider) {
        $errors[] = "Proveedor de noticias '$slug' no encontrado en la base de datos";
        continue;
      }
      
      // Obtener config_json actual
      $stmt = $pdo->prepare('SELECT config_json FROM news_providers WHERE id = ?');
      $stmt->execute([$provider['id']]);
      $currentConfig = $stmt->fetchColumn();
      
      $currentConfigArray = [];
      if ($currentConfig) {
        $decoded = json_decode($currentConfig, true);
        if (is_array($decoded)) {
          $currentConfigArray = $decoded;
        }
      }
      
      // Merge con la nueva configuración de prueba
      $currentConfigArray = array_merge($currentConfigArray, $config);
      $newConfigJson = json_encode($currentConfigArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      
      // Actualizar en la base de datos
      $stmt = $pdo->prepare('UPDATE news_providers SET config_json = ? WHERE id = ?');
      $stmt->execute([$newConfigJson, $provider['id']]);
      
      $results[] = [
        'provider_id' => (int)$provider['id'],
        'provider_slug' => $provider['slug'],
        'provider_name' => $provider['label'],
        'test_config' => $config['test'],
        'updated' => true
      ];
      $updated++;
      
      error_log("✅ Configuración de prueba agregada para {$provider['label']} ({$provider['slug']})");
      
    } catch (Exception $e) {
      $errors[] = "Error configurando '$slug': " . $e->getMessage();
      error_log("❌ Error configurando prueba para '$slug': " . $e->getMessage());
    }
  }
  
  json_out([
    'ok' => true,
    'message' => "Configuraciones de prueba agregadas para $updated proveedores de noticias",
    'results' => $results,
    'updated_count' => $updated,
    'errors' => $errors,
    'total_configs' => count($testConfigs)
  ]);
  
} catch (Exception $e) {
  error_log("Error en setup_news_providers_test_configs_safe.php: " . $e->getMessage());
  json_out(['error' => 'server-error', 'message' => 'Error interno del servidor: ' . $e->getMessage()], 500);
}
