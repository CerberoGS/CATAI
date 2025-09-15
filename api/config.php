<?php
// /bolsa/api/config.php
// EDITA estos valores con tus credenciales y llaves.
// Nota: las API keys de proveedores YA NO se gestionan aquí; se guardan por usuario en la base de datos.

// Autodetección de BASE_URL (respetando proxies) + override por ENV APP_BASE_URL
if (!function_exists('detect_base_url')) {
  function detect_base_url(): string {
    try {
      $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
      $host  = $_SERVER['HTTP_X_FORWARDED_HOST']  ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
      $path  = str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
      $path  = rtrim(preg_replace('#/api$#','', $path), '/'); // por si corre dentro de /api
      return rtrim("$proto://$host$path", '/');
    } catch (Throwable $e) {
      // Fallback seguro si hay error
      return 'https://cerberogrowthsolutions.com/catai';
    }
  }
}

// Usar detección automática para portabilidad completa
$BASE_URL = detect_base_url();

$CONFIG = [
  // ───────────── URLs Portables ─────────────
  'BASE_URL'     => $BASE_URL,              // p.ej. https://dominio.com/cataiv1
  'API_BASE_URL' => $BASE_URL . '/api',    // p.ej. https://dominio.com/cataiv1/api
  // ───────────── Base de datos ─────────────
  'DB_HOST' => 'localhost',         // Host MySQL de Hostinger (ver hPanel)
  'DB_PORT' => 3306,
  'DB_NAME' => 'u522228883_bolsa_app',
  'DB_USER' => 'u522228883_bolsa_user',
  'DB_PASS' => 'Bolsa3811',

  // ───────────── Auth (JWT) ───────────────
  'JWT_SECRET' => '2cc5ccdfa325bf6acd1ff0cc6c', // rota si lo deseas

  // ───── Clave maestra para cifrado de secretos de usuarios ─────
  // 32 bytes Base64. Si ya tienes una, mantenla. Para generar:
  // php -r "echo base64_encode(random_bytes(32));"
  'ENCRYPTION_KEY_BASE64' => 'sRDEMhrt53A8Nt4u0PbUCn6S9WPFGdAiWAOvdOdmj0A=',

  // ──────────── API keys globales (FALLBACK opcional) ────────────
  // Se dejan vacías a propósito: las claves por usuario vivirán en la base de datos.
  // Si quieres un fallback global temporal, puedes ponerlo aquí.
  'TIINGO_API_KEY'        => '',
  'ALPHAVANTAGE_API_KEY'  => '',
  'FINNHUB_API_KEY'       => '',
  'POLYGON_API_KEY'       => '',

  // ──────────── Prompts de IA ────────────
  'AI_PROMPT_EXTRACT_DEFAULT' => 'Eres un analista de trading experto con más de 20 años de experiencia en mercados financieros. Analiza este documento y proporciona un análisis estructurado en formato JSON con: resumen (2-3 líneas del contenido principal), puntos_clave (5-8 conceptos fundamentales extraídos), estrategias (3-5 estrategias de trading específicas), gestion_riesgo (2-3 puntos críticos de gestión de riesgo), recomendaciones (2-3 recomendaciones prácticas para implementar).',
  'GEMINI_API_KEY'        => '',
  'OPENAI_API_KEY'        => '',   // añadido por compatibilidad IA
  'XAI_API_KEY'           => '',   // añadido por compatibilidad IA (Grok)
  'ANTHROPIC_API_KEY'     => '',   // Claude
  'DEEPSEEK_API_KEY'      => '',   // DeepSeek

  // ─────────── Preferencias por defecto (si el usuario aún no guardó las suyas) ───────────
  'OPTIONS_DEFAULT_PROVIDER'      => 'auto',            // auto | polygon | finnhub
  'OPTIONS_DEFAULT_EXPIRY_RULE'   => 'nearest_friday',  // próximo viernes disponible
  'OPTIONS_DEFAULT_STRIKES_COUNT' => 20,                // ≈20 strikes alrededor del ATM
  'OPTIONS_DEFAULT_PRICE_SOURCE'  => 'series_last',     // series_last | provider

  // ─────────── Red: timeouts y reintentos por defecto ───────────
  'NET_DEFAULT_TIMEOUT_MS' => 8000,   // 8s por llamada
  'NET_DEFAULT_RETRIES'    => 2,      // reintentos para proveedores de datos

  // ─────────── Zona horaria por defecto ───────────
  // El usuario podrá sobreescribirla en config.html
  'APP_TIMEZONE' => 'America/Chicago',

  // ─────────── Rutas/otros ───────────
  'UNIVERSE_PATH' => __DIR__ . '/../data/universe.json',

  // CORS (configurado automáticamente para portabilidad)
  'ALLOWED_ORIGIN' => $BASE_URL,
  // Lista explícita de orígenes permitidos para CORS (mejores prácticas)
  // Incluye el dominio detectado automáticamente + localhost para desarrollo
  'ALLOWED_ORIGINS' => [
    $BASE_URL,
    // Para desarrollo local
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
  ],
  // Controla si se permiten credenciales en CORS (cookies/autorización). Por defecto true.
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
