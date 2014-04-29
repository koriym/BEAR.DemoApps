<?php

global $appDir;

$masterDb = $slaveDb = require __DIR__  .'/db/sqlite.php';
//list($masterDb, $slaveDb) = require __DIR__  .'/db/mysql.php';

/**
 * $context = [
 *     $config_name => $config_value
 * ];
 */
return [
    'prod' => [
        // required
        'app_name' => 'Demo\Sandbox',
        "app_class" => 'Demo\Sandbox\App',
        // optional
        'master_db' => $masterDb,
        'slave_db' => $slaveDb,
        // required
        'tmp_dir' => "{$appDir}/var/tmp",
        'log_dir' => "{$appDir}/var/log",
        'lib_dir' => "{$appDir}/var/lib",
        'resource_dir' => "{$appDir}/src/Resource",
    ],
    'dev' => [],
    'test' => [],
    'stub' => [],
];
