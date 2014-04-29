<?php

namespace Demo\Sandbox;

use BEAR\Package\Bootstrap\Bootstrap;


$appDir = dirname(__DIR__);
$packageDir = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
$loader = require $packageDir . '/vendor/autoload.php';

// Hierarchical profiler @see http://www.php.net/manual/en/book.xhprof.php
// require $packageDir . '/var/lib/profile.php';

Bootstrap::registerLoader(
    $loader,
    __NAMESPACE__,
    dirname(__DIR__)
);
