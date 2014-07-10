<?php
/**
 * Application instance script
 *
 * @return $app \BEAR\Sunday\Extension\Application\AppInterface
 *
 * @global $context string application context
 * @global $page
 */

namespace Demo\Sandbox;

use BEAR\Package\Bootstrap\Bootstrap;

require_once __DIR__ . '/autoload.php';

$appName = __NAMESPACE__;
$context = isset($context) ? $context : 'prod';
$tmpDir = dirname(__DIR__) . '/var/tmp';

$diCompiler = (new DiCompilerProvider($appName, $context, $tmpDir))->get($page);
$app = $diCompiler->getInstance('BEAR\Sunday\Extension\Application\AppInterface');

return $app;
