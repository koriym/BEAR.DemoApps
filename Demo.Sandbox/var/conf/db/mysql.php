<?php

global $appDir;

$mysql = [
    'driver' => 'pdo_mysql',
    'host' => 'localhost',
    'dbname' => 'sandbox',
    'charset' => 'UTF8'
];
$master = [
    'user' => $_SERVER['BEAR_MASTER_ID'],
    'password' => $_SERVER['BEAR_MASTER_PASSWORD']
];
$slave = [
    'user' => $_SERVER['BEAR_SLAVE_ID'],
    'password' => $_SERVER['BEAR_SLAVE_PASSWORD']
];

return [
    $mysql + $master,
    $mysql + $slave
];
