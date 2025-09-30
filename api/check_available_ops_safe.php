<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

json_header();

try {
    $user = require_user();
    $userId = $user['user_id'] ?? $user['id'] ?? null;
    
    $pdo = db();
    
    // Obtener settings del usuario
    $settingsStmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $settingsStmt->execute([$userId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $aiProviderId = (int)($settings['default_provider_id'] ?? 0);
    
    // Obtener ops_json del proveedor
    $provStmt = $pdo->prepare("SELECT id, slug, name, ops_json FROM ai_providers WHERE id = ? LIMIT 1");
    $provStmt->execute([$aiProviderId]);
    $providerRow = $provStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerRow) {
        json_out([
            'ok' => false,
            'error' => 'provider-not-found'
        ], 404);
    }
    
    $ops = json_decode($providerRow['ops_json'], true);
    
    $availableOps = array_keys($ops['multi'] ?? []);
    $hasVsStoreGet = isset($ops['multi']['vs.store.get']);
    $hasVsFiles = isset($ops['multi']['vs.files']);
    $hasSummarizePipeline = isset($ops['multi']['vs.summarize_from_vs']);
    
    json_out([
        'ok' => true,
        'provider' => [
            'id' => $providerRow['id'],
            'slug' => $providerRow['slug'],
            'name' => $providerRow['name']
        ],
        'operations' => [
            'available' => $availableOps,
            'total_count' => count($availableOps),
            'has_vs_store_get' => $hasVsStoreGet,
            'has_vs_files' => $hasVsFiles,
            'has_summarize_pipeline' => $hasSummarizePipeline
        ],
        'critical_ops_status' => [
            'vs.store.get' => $hasVsStoreGet ? '✅ Available' : '❌ Missing',
            'vs.files' => $hasVsFiles ? '✅ Available' : '❌ Missing', 
            'vs.summarize_from_vs' => $hasSummarizePipeline ? '✅ Available' : '❌ Missing'
        ]
    ]);
    
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => 'internal-error',
        'detail' => $e->getMessage()
    ], 500);
}
