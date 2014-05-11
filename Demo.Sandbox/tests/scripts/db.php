<?php

$appDir = $GLOBALS['APP_DIR'];
$conf = require dirname(dirname(__DIR__)) . '/var/conf/db/sqlite.php';
$db = new PDO("sqlite:{$conf['path']}");

return $db;
