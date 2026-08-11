<?php
declare(strict_types=1);

// An http server to be used as upstream backend by unit tests. _Do not use for anything else!_

/// @todo... allow usage of custom http headers to force returning 40x, 50x responses, xml responses instead of json,
///          enabling/disabling http features (compressed response bodies, keepalives, etc...)

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use TanoWAF\WAFCore\Tests\TestServer;

// NB: atm this, unlike waf.php, will not load env vars from server-specific config files .env.nginx and co...
$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__.'/../.env');

$server = new TestServer();

$server->preflight();
$server->respond(@$_SERVER['REQUEST_METHOD'] ?? 'GET', @$_GET['action'] ?? 'info', @$_GET['action_args'] ? (array)$_GET['action_args'] : []);
