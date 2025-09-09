<?php
require_once __DIR__ . '/helpers.php';
$u = require_user();
json_out($u);
?>