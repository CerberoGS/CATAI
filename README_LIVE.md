# Live Context & Session Log

Purpose
- Keep context in sync across chats so a new session can resume quickly.

Update Procedure (each iteration)
- Summarize changes in 3–7 bullets under the current session.
- List any new endpoints/params, frontend changes, migrations, and decisions.
- Update TODOs and Known Issues at the bottom.
- Keep details concise; link to files and lines when useful.

Quick Resume
- Frontends: `index.html` (analysis), `config.html` (keys + global prefs), `tester.html` (API sandbox).
- Backend: `api/` PHP endpoints; logs under `api/logs`.
- Auth: JWT in `localStorage.auth_token`.
- Settings ownership: `index.html` writes `data_provider` and its own fields; `config.html` writes global providers and extras.

Session Log
- Session 6 (current - 2025-01-27)
  - Sistema Híbrido IA: Implementado sistema híbrido completo de análisis con contexto enriquecido.
  - Knowledge Base Integration: Sistema de conocimiento integrado con análisis principal en `index.html`.
  - Context Enrichment: Prompts enriquecidos con contexto del Knowledge Base (200+ caracteres adicionales).
  - Hybrid Analysis Endpoint: Creado `api/ai_analyze_hybrid.php` con extracción avanzada de keywords.
  - Context Sources Display: Sección "Fuentes de Contexto Utilizadas" en `index.html` muestra origen del contexto.
  - Database Verification: Corregidos endpoints de verificación de base de datos (`check_database_tables.php`, `simple_db_check.php`).
  - SQL Syntax Fix: Resueltos errores de sintaxis SQL en consultas `SHOW TABLES LIKE` con parámetros preparados.
  - Diagnostic Tools: Herramientas de diagnóstico mejoradas en `diagnostico.html` para verificar estado del sistema híbrido.
  - System Status: Sistema híbrido completamente funcional con 6/7 tablas presentes, Knowledge Base operativo.
  - Integration Complete: Análisis en `index.html` ahora incluye contexto real del Knowledge Base automáticamente.
  - Professional Implementation: Sistema híbrido implementado sin romper funcionalidad existente del botón "Analizar".
- Session 5 (previous - 2025-01-27)
  - AI Behavioral System: Implementado sistema completo de IA comportamental profesional.
  - New AI Page: Creada `ai.html` con diseño UI/UX moderno y dashboard interactivo.
  - Learning Metrics: Sistema de métricas de aprendizaje con patrones comportamentales.
  - Behavioral Patterns: Detección automática de patrones de trading del usuario.
  - Analysis History: Historial completo de análisis con tracking de precisión.
  - Database Schema: Nuevas tablas para IA comportamental (`ai_behavioral_tables.sql`).
  - API Endpoints: 5 nuevos endpoints para el sistema de IA comportamental.
  - Intelligent Analysis: Análisis que combina datos técnicos + aprendizaje + patrones.
  - Personal Insights: Generación de insights personalizados basados en comportamiento.
  - Modern UI: Dashboard con métricas en tiempo real, gráficos y visualizaciones.
  - Integration Complete: Sistema de IA comportamental integrado con análisis principal.
  - Behavioral Integration: Nuevo módulo `ai-behavioral-integration.js` para análisis mejorado.
  - Enhanced Analysis: Botón "Analizar" en `index.html` ahora usa IA comportamental.
  - Real-time Dashboard: Página `ai.html` muestra métricas en tiempo real.
  - API Endpoints: `ai_learning_metrics_safe.php`, `ai_behavioral_patterns_safe.php`, `ai_analysis_save_safe.php`, `ai_analysis_history_safe.php`, `ai_learning_events_safe.php`.
  - UI/UX Improvements: Mejorada visualización de resultados en `ai.html` con estructura similar a `index.html`.
  - Configuration Sync: Sincronización automática de configuración entre `ai.html` e `index.html`.
  - Enhanced Display: Agregados datos técnicos simulados y mejor estructura visual en resultados.
  - Cross-Page Integration: Configuración de IA se comparte entre páginas sin romper funcionalidad existente.
  - Professional Polish: Mejoras incrementales implementadas con enfoque senior sin romper código existente.
  - Behavioral Analysis Integration: `index.html` ahora usa TODA la configuración de `ai.html` para análisis profesional.
  - Enriched Prompts: Prompts enriquecidos con contexto comportamental, métricas de aprendizaje y configuración personalizada.
  - Visual Indicators: Indicadores visuales en `index.html` muestran cuando se usa IA comportamental y configuración aplicada.
  - Configuration Display: Sección que muestra la configuración de IA utilizada en cada análisis.
  - Professional Workflow: Flujo profesional donde `ai.html` configura comportamiento y `index.html` ejecuta análisis con esa configuración.
  - Session 4 (previous)
    - Knowledge Base: Creado `KNOWLEDGE_BASE.md` con documentación completa del sistema.
    - Context Quick: Añadido `CONTEXT_QUICK.md` para inicio rápido de sesiones.
    - Analysis Complete: Análisis exhaustivo de arquitectura, endpoints, frontend y base de datos.
    - Documentation: Documentados flujos principales, esquema DB, funcionalidades clave.
    - TODOs Identified: Lista priorizada de mejoras pendientes (cifrado, tooling, docs).
    - Architecture: Sistema modular PHP+JS con IA comportamental y sistema de conocimiento.
    - Security: JWT HS256, CORS, prepared statements, cifrado AES-256-GCM (✅ COMPLETADO).
    - UI/UX: Soporte completo ES/EN, modo claro/oscuro, accesibilidad WCAG AA.
    - Crypto Implementation: Implementado cifrado AES-256-GCM para API keys.
      - Archivos: `api/crypto.php` (funciones de cifrado), `api/helpers.php` (funciones actualizadas)
      - Scripts: `migrate_encrypt_keys.php`, `test_crypto.php`, `verify_crypto_setup.php`
      - Funciones: `set_api_key_for()` y `get_api_key_for()` ahora cifran/descifran automáticamente
      - Migración: Script para cifrar claves existentes en texto plano
      - Verificación: Scripts de prueba para validar funcionamiento del cifrado
- Session 3 (previous)
  - Config: Replaced manual tz offset with full IANA time zone dropdown in `config.html`.
  - Persist: Now writes `time_zone` (IANA) and derived `tz_offset` (e.g., +02:00) for compatibility.
  - UI: Shows current GMT offset; defaults to browser time zone; hides legacy offset input.
  - Import/Export: Includes `time_zone` alongside `tz_offset`.
  - Load/Reset: Preselects saved `time_zone` or browser zone; offset display updates on change.
  - Phase 1 Journal: Added analysis history (save/list/get/update + upload) and `journal.html`.
  - Endpoints: `analysis_save_safe.php`, `analysis_list_safe.php`, `analysis_get_safe.php`, `analysis_update_safe.php`, `analysis_upload.php`.
  - Frontend: `index.html` modal “Guardar análisis” (title, notes, traded, outcome, one image). Button “Bitácora”.
  - Frontend: `journal.html` list with basic filters, view/edit modal, and “Reabrir en analizador”.
  - Enhancements: Multiple attachments on save, edit-time attachment add/delete, delete analysis, date filters, PnL/currency fields in modal.
  - Feedback Phase A: Added feedback capture.
    - Endpoints: `feedback_save_safe.php`, `feedback_upload.php`.
    - UI: “Feedback” button + modal on `index.html`, `config.html`, `journal.html` with type/severity/module/title/description, multi-attachments, and optional diagnostics.
  - Feedback Phase B: Added triage and updates.
    - Endpoints: `feedback_list_safe.php`, `feedback_get_safe.php`, `feedback_update_safe.php`, `feedback_attachment_delete_safe.php`.
    - Page: `feedback.html` with filters, pagination, detail modal (status updates, edit title/description), attachments add/delete, diagnostics view.
  - [fill here] Open questions/decisions.
  - [fill here] Validation steps (tester.html, logs, etc.).
  - Auth UX: Añadidos `login.html`, `account.html`, `admin.html` y helper `static/auth.js` (guards JWT). Protegidas páginas `config.html`, `journal.html`, `feedback.html`, `tester.html` con `Auth.requireAuth()`. `admin.html` exige rol admin por JWT.
- Session 2 (summary)
  - [pending: backfill once provided]
- Session 1 (summary)
  - [pending: backfill once provided]

TODOs (rolling)
- [fill here] Pending tasks with owner/context.

Known Issues
- [fill here] Repro steps, expected vs actual, links to code.
## Session: Auth UX split (2025-09-06)
- Nuevas páginas: `login.html` (inicio aparte), `account.html` (cuenta), `admin.html` (panel admin mínimo).
- Nuevo helper: `static/auth.js` (JWT decode/guard/fetch wrapper/logout).
- Páginas protegidas: `config.html`, `journal.html`, `feedback.html`, `tester.html` ahora exigen token. `index.html` mantiene login embebido (no redirección forzada).
- Validación: iniciar sesión en `login.html`; navegar a `index.html`, `config.html`, `account.html`; si admin, probar `admin.html`.
## Session: Auth harden (2025-09-06)
- Login/Register: normalización de email via `normalize_email()` y validación de contraseña (mín. 8).
- Login/Register: removido `ALTER TABLE ... is_admin` en runtime; fallback de SELECT si la columna no existe.
- Archivos: `api/auth_login.php`, `api/auth_register.php`.
- Validación:
  - Registro: `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/auth_register.php -H "Content-Type: application/json" -d '{"email":"user@x.com","password":"12345678"}'`
  - Login: `curl -sX POST https://cerberogrowthsolutions.com/bolsa/api/auth_login.php -H "Content-Type: application/json" -d '{"email":"user@x.com","password":"12345678"}'`
  - Si tu DB no tiene `is_admin`, el login sigue funcionando (fallback de SELECT).
## Session: AGENTS bootstrap (2025-09-06)
- Stack/estructura detectados: PHP 8+ sin framework en `api/` (JWT, CORS, PDO MySQL), HTML/JS estático en raíz; `.htaccess` para SPA; datos mixtos (MySQL + JSON en `api/data/`).
- Scripts verificados (build/test/lint): no existen scripts declarados; validación vía cURL y `tester.html`.
- Decisiones/documentadas en AGENTS.md: inicio rápido con `api/config.sample.php`→`api/config.php`, contratos exactos de `auth_*`, `settings_*_safe.php`, `user_keys_*_safe.php`/`secrets_*_safe.php`, `time_series_safe.php`, `ai_analyze.php`; CORS/JWT; flujo de validación manual.
- TODOs inmediatos (3–5): unificar storage de claves y cifrar (`crypto.php`), definir `cfg()` o eliminar usos, agregar tooling para dev local/SPA fallback, revisar allowlist CORS de dev.
- Cómo validar (comandos exactos): cURL a `api/auth_login.php` para token; `api/settings_get_safe.php`, `api/settings_set_safe.php`, `api/time_series_safe.php`, `api/ai_analyze.php` con `Authorization: Bearer <token>`.
