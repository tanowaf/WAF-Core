<?php
declare(strict_types=1);

// *** A _minimalist_ php webpage, to be used for load tests. Does nothing and returns an empty page. Useful as baseline for php code.

if (!isset($_SERVER['FRANKENPHP_WORKER']) || (int)$_SERVER['FRANKENPHP_WORKER'] === 0 || !function_exists('frankenphp_handle_request')) {
    throw new \Exception('This script is meant to be used in FrankenPHP Worker mode, which is not enabled in the current configuration');
}

// FrankenPHP worker mode

$requestHandler = function() { };

$maxRequests = (isset($_SERVER['MAX_REQUESTS_PER_WORKER']) && $_SERVER['MAX_REQUESTS_PER_WORKER'] > 0) ? (int)$_SERVER['MAX_REQUESTS_PER_WORKER'] : PHP_INT_MAX;

for ($nbRequests = 0; $nbRequests < $maxRequests; ++$nbRequests) {

    $keepRunning = \frankenphp_handle_request($requestHandler);

    if (!$keepRunning) break;
}
