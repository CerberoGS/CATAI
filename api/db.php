<?php
// /bolsa/api/db.php
declare(strict_types=1);

// Cargar config de una sola fuente: si helpers.php ya cargó __APP_CONFIG, úsalo.
$config = isset($GLOBALS['__APP_CONFIG']) && is_array($GLOBALS['__APP_CONFIG'])
  ? $GLOBALS['__APP_CONFIG']
  : (require __DIR__ . '/config.php');

if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    $cfg = isset($GLOBALS['__APP_CONFIG']) && is_array($GLOBALS['__APP_CONFIG'])
      ? $GLOBALS['__APP_CONFIG']
      : (require __DIR__ . '/config.php');

    if ($pdo instanceof PDO) return $pdo;

    $host = (string)($cfg['DB_HOST'] ?? 'localhost');
    $port = (int)($cfg['DB_PORT'] ?? 3306);
    $name = (string)($cfg['DB_NAME'] ?? '');
    $user = (string)($cfg['DB_USER'] ?? '');
    $pass = (string)($cfg['DB_PASS'] ?? '');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
      PDO::ATTR_TIMEOUT            => 10,
    ];
    if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
      $options[constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS')] = false;
    }
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
      $options[constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = "SET NAMES utf8mb4";
    }

    // Log mínimo de diagnóstico (usuario enmascarado) en caso de fallo
    try {
      $pdo = new PDO($dsn, $user, $pass, $options);
      return $pdo;
    } catch (Throwable $e) {
      try {
        $dir = __DIR__ . '/logs'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $mask = function(string $s){ return $s === '' ? '' : substr($s,0,3) . str_repeat('*', max(0, strlen($s)-3)); };
        $row = [
          'ts' => date('c'),
          'ev' => 'db_connect_error',
          'dsn'=> $dsn,
          'user'=> $mask($user),
          'detail' => $e->getMessage(),
        ];
        $dbLog = $dir.'/db.log';
        if (function_exists('rotate_log')) { @rotate_log($dbLog, 524288, 3); }
        @file_put_contents($dbLog, json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
      } catch(\Throwable $ignore) {}
      throw $e;
    }
  }
}

if (!function_exists('db_tx')) {
  function db_tx(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $res = $fn($pdo);
      $pdo->commit();
      return $res;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
