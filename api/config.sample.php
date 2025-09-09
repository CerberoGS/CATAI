<?php
// /bolsa/api/config.sample.php
// Copia este archivo como config.php y completa tus credenciales.
// NO subas config.php al repositorio (está ignorado por .gitignore).

return [
  // Base de datos (MySQL)
  'DB_HOST' => 'localhost',
  'DB_PORT' => 3306,
  'DB_NAME' => 'your_db_name',
  'DB_USER' => 'your_db_user',
  'DB_PASS' => 'your_db_password',

  // Auth (JWT). Cambia por un secreto aleatorio y largo
  // Generar: php -r "echo bin2hex(random_bytes(24));"
  'JWT_SECRET' => 'CHANGE_ME_WITH_A_LONG_RANDOM_VALUE',

  // Clave maestra (32 bytes base64) para cifrado de secretos
  // Generar: php -r "echo base64_encode(random_bytes(32));"
  'ENCRYPTION_KEY_BASE64' => 'REPLACE_WITH_BASE64_32_BYTES',

  // API keys globales (fallback opcional). Dejar vacío si gestionas por usuario
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

  // Rutas/otros
  'UNIVERSE_PATH' => __DIR__ . '/../data/universe.json',

  // CORS: lista explícita de orígenes permitidos
  // Agrega aquí tus dominios (producción y desarrollo si aplica)
  'ALLOWED_ORIGINS' => [
    'https://cerberogrowthsolutions.com',
    // 'http://localhost:3000',
  ],
  'CORS_ALLOW_CREDENTIALS' => true,

  // Red IA
  'NET' => [
    'ia_timeout_ms' => 20000,
    'ia_retries'    => 1,
  ],
];
