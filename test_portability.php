<?php
/**
 * Script de prueba para verificar portabilidad de URLs
 * Ejecutar desde diferentes dominios/carpetas para validar configuración
 */

// Simular diferentes entornos
$testEnvironments = [
    [
        'name' => 'Producción actual',
        'server' => [
            'HTTP_HOST' => 'cerberogrowthsolutions.com',
            'REQUEST_URI' => '/catai/api/test_portability.php',
            'SCRIPT_NAME' => '/catai/api/test_portability.php',
            'HTTPS' => 'on'
        ]
    ],
    [
        'name' => 'Subcarpeta diferente',
        'server' => [
            'HTTP_HOST' => 'midominio.com',
            'REQUEST_URI' => '/miapp/api/test_portability.php',
            'SCRIPT_NAME' => '/miapp/api/test_portability.php',
            'HTTPS' => 'on'
        ]
    ],
    [
        'name' => 'Raíz del dominio',
        'server' => [
            'HTTP_HOST' => 'otrodominio.com',
            'REQUEST_URI' => '/api/test_portability.php',
            'SCRIPT_NAME' => '/api/test_portability.php',
            'HTTPS' => 'on'
        ]
    ],
    [
        'name' => 'Desarrollo local',
        'server' => [
            'HTTP_HOST' => 'localhost:8000',
            'REQUEST_URI' => '/api/test_portability.php',
            'SCRIPT_NAME' => '/api/test_portability.php'
        ]
    ]
];

require_once 'api/config.php';
require_once 'api/helpers.php';

echo "<h1>Prueba de Portabilidad CATAI</h1>";
echo "<p>Entorno actual detectado:</p>";
echo "<ul>";
echo "<li><strong>BASE_URL:</strong> " . $BASE_URL . "</li>";
echo "<li><strong>API_BASE_URL:</strong> " . $CONFIG['API_BASE_URL'] . "</li>";
echo "<li><strong>getApiUrl():</strong> " . getApiUrl() . "</li>";
echo "<li><strong>getApiUrl('auth_me.php'):</strong> " . getApiUrl('auth_me.php') . "</li>";
echo "</ul>";

echo "<h2>Simulación de diferentes entornos:</h2>";
foreach ($testEnvironments as $env) {
    echo "<h3>{$env['name']}</h3>";
    echo "<ul>";

    // Simular variables del servidor
    $originalServer = $_SERVER;
    $_SERVER = array_merge($_SERVER, $env['server']);

    // Recargar configuración
    $testConfig = require 'api/config.php';
    require_once 'api/helpers.php'; // Recargar helpers

    echo "<li><strong>HTTP_HOST:</strong> " . ($_SERVER['HTTP_HOST'] ?? 'N/A') . "</li>";
    echo "<li><strong>SCRIPT_NAME:</strong> " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A') . "</li>";
    echo "<li><strong>Detectado BASE_URL:</strong> " . ($testConfig['BASE_URL'] ?? 'ERROR') . "</li>";
    echo "<li><strong>Detectado API_BASE_URL:</strong> " . ($testConfig['API_BASE_URL'] ?? 'ERROR') . "</li>";
    echo "<li><strong>getApiUrl():</strong> " . (function_exists('getApiUrl') ? getApiUrl() : 'ERROR') . "</li>";
    echo "<li><strong>getApiUrl('test.php'):</strong> " . (function_exists('getApiUrl') ? getApiUrl('test.php') : 'ERROR') . "</li>";

    // Restaurar servidor original
    $_SERVER = $originalServer;

    echo "</ul>";
}

echo "<h2>Configuración CORS:</h2>";
echo "<pre>" . json_encode($CONFIG['ALLOWED_ORIGINS'], JSON_PRETTY_PRINT) . "</pre>";

echo "<h2>Estado de portabilidad:</h2>";
$issues = [];

if (strpos($BASE_URL, 'cerberogrowthsolutions.com') !== false && strpos($BASE_URL, 'localhost') === false) {
    $issues[] = "⚠️ BASE_URL contiene dominio hardcodeado";
}

if (function_exists('getApiUrl')) {
    $testUrl = getApiUrl('test.php');
    if (strpos($testUrl, 'cerberogrowthsolutions.com') !== false && strpos($testUrl, 'localhost') === false) {
        $issues[] = "⚠️ getApiUrl() retorna URL hardcodeada";
    } else {
        echo "<p>✅ getApiUrl() usa detección automática</p>";
    }
} else {
    $issues[] = "❌ getApiUrl() no está definida";
}

if (empty($issues)) {
    echo "<p style='color: green; font-weight: bold;'>✅ Configuración completamente portable</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Problemas encontrados:</p>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li>$issue</li>";
    }
    echo "</ul>";
}
?>