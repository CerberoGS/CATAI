AGENTS.md — Guía para Agentes de IA

Objetivo
- Establecer pautas claras para operar en este repo sin inventar comportamientos.
- Basado en el código real: PHP bajo `api/` y frontends HTML en la raíz.

Stack y Estructura
- Backend: PHP 8+ sin framework; endpoints JSON en `api/`.
- Frontend: HTML/JS estáticos (`index.html`, `config.html`, `tester.html`, `journal.html`, `feedback.html`).
- Servidor: pensado para Apache con `.htaccess` (SPA + CORS); `api/.htaccess` desactiva rewrites.
- Datos: MySQL vía PDO (`api/db.php`) y archivos JSON en `api/data/` para ciertos prefs/logs.
- Seguridad: JWT HS256 (`api/jwt.php` y `api/helpers.php::require_user()`), CORS configurable en `api/config.php`, cabeceras seguras básicas (`header_remove('X-Powered-By')`).

Inicio Rápido (local)
- Requisitos: PHP 8+, MySQL. No hay Composer ni Node en este repo.
- Copia y configura:
  - `api/config.sample.php` → `api/config.php` (DB, `JWT_SECRET`, `ENCRYPTION_KEY_BASE64`, CORS, API keys fallback opcionales).
  - `.gitignore` ya excluye `api/config.php` y logs.
- Servir:
  - Apache recomendado para respetar `.htaccess` (SPA y cabeceras). Ruta esperada: raíz del repo, endpoints bajo `/api/*.php`.
  - Alternativa simple: `php -S 127.0.0.1:8000` en la raíz sirve `index.html` y `/api/*.php` sin fallback SPA (navega desde `/` o `index.html`).
- Base URL front: los HTML llaman a `/bolsa/api` en algunos flujos legados; en este repo, los endpoints viven en `api/`. Ajusta el `origin`/path si tu despliegue no está bajo `/bolsa/`.

Comandos existentes (build/test/lint)
- No hay scripts ni tooling declarados (no `composer.json`/`package.json`/tests). Usa los endpoints reales para validar.

Convenciones de Código
- PHP: `declare(strict_types=1);`, respuestas JSON consistentes con `json_out()/json_error()` en `api/helpers.php`.
- Autenticación: `Authorization: Bearer <jwt>` obligatorio en endpoints protegidos (usa `require_user()`).
- DB: PDO con prepared statements y `ATTR_EMULATE_PREPARES=false` (`api/db.php`).
- Logs: en `api/logs/*.log` con rotación básica (`rotate_log`).
- CORS: `ALLOWED_ORIGINS`/`ALLOWED_ORIGIN`, `CORS_ALLOW_CREDENTIALS` en `api/config.php`; `helpers::apply_cors()` maneja preflight.

Seguridad (implementado vs. pendiente)
- Implementado: JWT HS256, CORS con allowlist, ocultar `X-Powered-By`, cabeceras JSON, prepared statements.
- API keys por usuario: endpoints `user_keys_*_safe.php` guardan claves en DB (texto plano actualmente). Existen utilidades de cifrado AES-256-GCM en `api/crypto.php` y flujos alternativos `secrets_*_safe.php` que las usan.
- TODOs:
  - Unificar almacenamiento de claves para que `set_api_key_for/get_api_key_for` usen cifrado (hoy escribe/lee plano).
  - Definir helper `cfg()` o reemplazar llamadas (usado en `crypto.php` y `secrets_*_safe.php`) para evitar fallos si no está definido.
  - Revisar orígenes CORS de desarrollo en `config.php`.

API/Contratos (exacto a archivos)
- Auth
  - `POST api/auth_register.php` → `{ email, password, name? }` ⇒ `{ token, user }`
  - `POST api/auth_login.php`    → `{ email, password }` ⇒ `{ token, user }`
  - `GET  api/auth_me.php`       → header Bearer ⇒ payload JWT decodificado
- Settings
  - `GET  api/settings_get_safe.php` ⇒ `{ ok, settings: { series_provider, options_provider, data_provider?, resolutions_json, indicators_json, ai_provider, ai_model, ...extras } }`
  - `POST api/settings_set_safe.php` → subset como `{ data_provider, resolutions_json, indicators_json, ai_provider, ai_model, symbol, amount, tp, sl, options_expiry_rule, options_strike_count, atm_price_source, net }` ⇒ `{ ok, saved: {...} }`
- Claves/API Keys (DB plano)
  - `GET  api/user_keys_get_safe.php` ⇒ `{ ok, keys: { provider: { has, last4 } } }`
  - `POST api/user_keys_set_safe.php` ⇒ `{ openai:"sk-...", gemini:"..." }` o `{ set:{}, delete:[] }` ⇒ `{ ok, saved, deleted, skipped }`
- Claves/Preferencias (cifrado + prefs)
  - `GET  api/secrets_get_masked_safe.php` ⇒ `{ ok, secrets_masked:{...}, available_providers:[...], options_prefs, net_prefs }`
  - `POST api/secrets_set_safe.php`       ⇒ `{ secrets:{ tiingo_api_key, ... }, options_prefs?, net_prefs? }` ⇒ `{ ok:true }`
- Series de tiempo (proveedores: AlphaVantage/Finnhub/Tiingo)
  - `POST api/time_series_safe.php` → `{ symbol, provider:'auto'|'alphavantage'|'finnhub'|'tiingo', resolutions:['daily'|'weekly'|'1min'|'5min'|'15min'|'30min'|'60min'] }` ⇒ `{ provider, symbol, seriesByRes:{ [reso]: { provider, indicators:{ last:{ price,rsi14,sma20,ema20,ema40,ema100,ema200 } }, fallback? , error? } } }`
- Análisis IA
  - `POST api/llm.php` → encamina a `ai_analyze.php`/`ia_analyze.php` si existen.
  - `POST api/ai_analyze.php` → `{ provider:'auto'|'gemini'|'openai'|'claude'|'xai'|'deepseek', model?, prompt, systemPrompt? }` ⇒ `{ text, provider, model }` o `{ error,... }`
- Bitácora de análisis (Journal)
  - `POST api/analysis_save_safe.php`  → guarda análisis + adjuntos (URLs)
  - `GET  api/analysis_list_safe.php`  → `?limit&offset&symbol&outcome&traded&q&from&to`
  - `GET  api/analysis_get_safe.php`   → `?id`
  - `POST api/analysis_update_safe.php`
  - `POST api/analysis_upload.php`     → subida de archivo (ver HTML `index.html`/`journal.html`)
  - `POST api/analysis_delete_safe.php`
- Feedback
  - `POST api/feedback_save_safe.php`, `GET api/feedback_list_safe.php`, `GET api/feedback_get_safe.php`, `POST api/feedback_update_safe.php`, `POST api/feedback_attachment_delete_safe.php`
- Utilidades
  - `GET api/health.php`, `GET api/_health.php`, `POST api/log_debug.php`, `GET api/db_check.php`

Flujo de cambios/PR
- No hay CI configurado. Checks manuales recomendados antes de integrar:
  - No versionar `api/config.php` ni claves.
  - Probar `auth_*`, `settings_*_safe.php`, `user_keys_*_safe.php` o `secrets_*_safe.php`, `time_series_safe.php`, `ai_analyze.php` con un token válido.
  - Verificar logs en `api/logs/` y respuestas JSON consistentes.

Validación (cURL ejemplos)
- Registrar y login (obtener `TOKEN`):
  - `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/auth_register.php -H "Content-Type: application/json" -d '{"email":"u@x.com","password":"p"}'`
  - `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/auth_login.php -H "Content-Type: application/json" -d '{"email":"u@x.com","password":"p"}'`
- Settings GET/SET:
  - `curl -s https://cerberogrowthsolutions.com/bolsa/api/settings_get_safe.php -H "Authorization: Bearer $TOKEN"`
  - `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/settings_set_safe.php -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"data_provider":"finnhub"}'`
- Series:
  - `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/time_series_safe.php -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"symbol":"TSLA","provider":"auto","resolutions":["daily","weekly"]}'`
- IA:
  - `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/ai_analyze.php -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -d '{"prompt":"Hola"}'`
  - Nota: para entorno local de desarrollo, sustituye el origen por `http://localhost:8000`.

Nota de Jerarquía
- Si en alguna subcarpeta aparece otro `AGENTS.md`, ese documento tiene prioridad en su ámbito.

TODOs claros
- Implementar helper `cfg()` en `helpers.php` (o eliminar su uso) y alinear `crypto.php`/`secrets_*` con `user_keys_*`.
- Cifrar claves en `user_api_keys` usando AES-256-GCM (ver `api/crypto.php`).
- Añadir instructivo/Makefile o Docker para dev local y pruebas.
- Documentar router local para fallback SPA si no se usa Apache.
