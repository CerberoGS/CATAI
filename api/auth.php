<?php
/**
 * auth.php — shim de compatibilidad
 * No declara funciones; solo centraliza includes usados por endpoints legacy.
 * Evita “Cannot redeclare require_user()” cuando helpers.php ya la define.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
// Si en algún endpoint viejo se hacía `require 'auth.php'` para cargar utilidades,
// con este shim basta: helpers.php ya expone require_user(), read_json_body(), etc.
// No declarar aquí funciones para no duplicar definiciones.
