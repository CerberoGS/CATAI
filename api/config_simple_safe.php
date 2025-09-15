<?php
// Configuración ultra simple para debug
$CONFIG = [
    // URLs Portables - hardcodeadas temporalmente
    'BASE_URL'     => 'https://cerberogrowthsolutions.com/catai',
    'API_BASE_URL' => 'https://cerberogrowthsolutions.com/catai/api',
    
    // Base de datos
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'u522228883_bolsa_app',
    'DB_USER' => 'u522228883_bolsa_user',
    'DB_PASS' => 'Bolsa3811',

    // Auth (JWT)
    'JWT_SECRET' => '2cc5ccdfa325bf6acd1ff0cc6c',

    // Clave maestra para cifrado
    'ENCRYPTION_KEY_BASE64' => 'sRDEMhrt53A8Nt4u0PbUCn6S9WPFGdAiWAOvdOdmj0A=',

    // API keys globales (vacías)
    'TIINGO_API_KEY'        => '',
    'ALPHAVANTAGE_API_KEY'  => '',
    'FINNHUB_API_KEY'       => '',
    'POLYGON_API_KEY'       => '',
    'GEMINI_API_KEY'        => '',
    'OPENAI_API_KEY'        => '',
    'XAI_API_KEY'           => '',
    'ANTHROPIC_API_KEY'     => '',
    'DEEPSEEK_API_KEY'      => '',

    // Preferencias por defecto
    'OPTIONS_DEFAULT_PROVIDER'      => 'auto',
    'OPTIONS_DEFAULT_EXPIRY_RULE'   => 'nearest_friday',
    'OPTIONS_DEFAULT_STRIKES_COUNT' => 20,
    'OPTIONS_DEFAULT_PRICE_SOURCE'  => 'series_last',

    // Red
    'NET_DEFAULT_TIMEOUT_MS' => 8000,
    'NET_DEFAULT_RETRIES'    => 2,

    // Zona horaria
    'APP_TIMEZONE' => 'America/Chicago',

    // Rutas
    'UNIVERSE_PATH' => __DIR__ . '/../data/universe.json',

    // CORS
    'ALLOWED_ORIGIN' => 'https://cerberogrowthsolutions.com/catai',
    'ALLOWED_ORIGINS' => [
        'https://cerberogrowthsolutions.com/catai',
    ],
    'CORS_ALLOW_CREDENTIALS' => true,

    // Preferencias de red (IA)
    'NET' => [
        'ia_timeout_ms' => 20000,
        'ia_retries'    => 1,
    ],
];

// Establecer variable global para cfg()
$GLOBALS['__APP_CONFIG'] = $CONFIG;

return $CONFIG;
