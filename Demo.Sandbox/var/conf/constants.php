<?php
/**
 * Constants
 *
 * [
 *  $context1 => [$config_name => $config_value],
 *  $context2 => [$config_name => $config_value],
 * ]
 *
 * default constants:
 *  'namespace' => 'Demo\Sandbox',
 *  'app_class' => 'Demo\Sandbox\App',
 *  'tmp_dir' => "{$appDir}/var/tmp",
 *  'log_dir' => "{$appDir}/var/log",
 *  'lib_dir' => "{$appDir}/var/lib",
 *  'resource_dir' => "{$appDir}/src/Resource"
 */

$masterDb = $slaveDb = require __DIR__  .'/db/sqlite.php';
//list($masterDb, $slaveDb) = require __DIR__  .'/db/mysql.php';

return [
    'prod' => [
        // optional
        'master_db' => $masterDb,
        'slave_db' => $slaveDb,
    ],
    'dev' => [],
    'test' => [],
    'stub' => [],
];
