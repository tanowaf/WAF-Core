<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use TanoWAF\WAFCore\Tests\DotConf;

$dotConf = new DotConf();
$dotConf->loadEnv($_ENV['SERVER_TYPE'], $_ENV['WAF_TYPE'] ?? '');
