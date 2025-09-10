# Bolsa AI — Developer Notes

This document captures the minimum you need to onboard and extend the app safely without re‑introducing the past issues (provider overlap, encoding, and endpoint mismatches).

## Overview
- Single‑page frontends: `index.html` (main analyzer), `config.html` (keys + global prefs), `tester.html` (API sandbox).
- Backend (PHP) under `api/`: JSON endpoints, JWT auth, DB via PDO, logs in `api/logs`.
- Auth: Bearer token in `localStorage.auth_token` (legacy key `token` is migrated on load).

## Data Ownership (very important)
- Global providers (managed by `config.html`):
  - `user_settings.series_provider`
  - `user_settings.options_provider`
- Index page provider (separate field to avoid overlap):
  - `user_settings.data_provider` (column preferred); if the column is absent, it is also persisted inside `user_settings.data` JSON under `data_provider` for compatibility.
- Index page user settings:
  - `resolutions_json`, `indicators_json`, `ai_provider`, `ai_model`, and extras in `data` JSON: `amount`, `tp`, `sl`.
- Global extras (stored in `data` JSON):
  - `options_expiry_rule`, `options_strike_count`, `atm_price_source`, `tz_offset`, and `net.{polygon|finnhub|tiingo}.timeout_ms/retries`.

Rules:
- `config.html` only modifies global providers + extras (never the index provider).
- `index.html` never writes `series_provider` or `options_provider`; it writes `data_provider` and its own settings/extras.

## Frontends
- `index.html`
  - Sends: `data_provider`, `resolutions_json`, `indicators_json`, `ai_provider`, `ai_model`, `amount`, `tp`, `sl`.
  - Loads: prefers `data_provider`, falls back to `series_provider` if missing.
- `config.html`
  - Keys: `user_keys_*_safe.php` (test, get, set, delete); inputs can be empty to keep stored key.
  - Preferences: writes `series_provider`, `options_provider`, and extras listed above.
- `tester.html`
  - SAFE/PLAIN toggles for quick regression checks of auth/settings/keys/time series/options.

## Backend Endpoints
- Settings
  - `settings_set_safe.php` → `settings_set.php`: merge write, partial updates respected (fields not sent are preserved).
  - `settings_get_safe.php` → `settings_get.php`: returns `settings` merging columns + `data` JSON.
- Keys
  - `user_keys_get_safe.php`: returns presence + masked last4 per provider.
  - `user_keys_set_safe.php`: accepts flat fields or `{ set: {}, delete: [] }`.
  - `key_test_safe.php`: tests provider connectivity; if input field empty, uses stored key.
- Utilities
  - `log_debug.php` (simple JSON logger), `db_check.php` (environment checks).

## Persistence (`user_settings`)
Core columns:
- `id`, `user_id`
- `series_provider` (varchar 32, default 'auto')
- `options_provider` (varchar 32, default 'auto')
- `data_provider` (varchar 32, nullable) — may be absent in old DBs; backend also keeps `data_provider` inside `data` JSON if column missing.
- `resolutions_json` (longtext JSON)
- `indicators_json` (longtext JSON)
- `ai_provider` (varchar 32, default 'auto')
- `ai_model` (varchar 128, nullable)
- `data` (longtext JSON for extras)
- `updated_at` (timestamp)

Add column (if you want it explicitly in DB):
```
ALTER TABLE user_settings
  ADD COLUMN data_provider VARCHAR(32) NULL AFTER options_provider;
```

## Network & Auth
- Always call `window.location.origin + '/bolsa/api'` to avoid origin drift.
- Each request includes `Authorization: Bearer <token>` from `localStorage.auth_token`.
- JSON only, `helpers.php` enforces `Content-Type: application/json; charset=utf-8` and consistent error shapes.

## Diagnostics
- `api/logs/prefs.log`: look for `settings_set:input`, `settings_set:upsert`, `settings_get:row/response`.
- `api/logs/db.log`: connection failures (DSN + user masked). Useful to distinguish environment vs. logic issues.
- Use `tester.html` to quickly exercise `*_safe.php` endpoints.

## Encoding & CORS
- `.htaccess` forces UTF‑8 for `.html/.js/.css` and sets `Content-Language: es-US`.
- Keep sources saved as UTF‑8 (use editor “Reopen with Encoding → Save with Encoding UTF‑8”). Avoid double‑encoded strings in HTML.

## Safe Changes Checklist
- Do not let `index.html` write `series_provider/options_provider`.
- If you add new front fields, prefer adding them to `data` JSON unless they are truly “core” columns.
- When changing `settings_set.php`, preserve partial update semantics (do not blank fields not present in payload).
- Verify any change with `tester.html` and watch Network payloads.

## Quick API Cheat Sheet
- GET settings (SAFE)
  - `GET /bolsa/api/settings_get_safe.php` → `{ ok, settings: { series_provider, options_provider, data_provider?, resolutions_json, indicators_json, ai_provider, ai_model, ...extras } }`
- SET settings (SAFE)
  - `POST /bolsa/api/settings_set_safe.php`
  - Body (JSON): any subset, e.g. `{ data_provider, resolutions_json, indicators_json, ai_provider, ai_model, amount, tp, sl }`
- GET keys (SAFE)
  - `GET /bolsa/api/user_keys_get_safe.php` → `{ ok, keys: { provider: { has, last4 } } }`
- SET keys (SAFE)
  - `POST /bolsa/api/user_keys_set_safe.php` with `{ openai: 'sk-...', gemini: '...' }` or `{ set:{}, delete:[] }`

## What changed recently (high‑value)
- Separated index provider into `data_provider` (no more overwriting global providers).
- Fixed `config.html` corruption; rebuilt with clean HTML/JS, absolute API base and token handling.
- Added `amount`, `tp`, `sl` to index settings; rehydrates on load.
- Server‑side merge updates to avoid unintentionally blanking fields.

If you need deeper details (logs, response examples), use `tester.html` to capture exact payloads and responses per flow.

