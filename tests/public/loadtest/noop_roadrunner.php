<?php
declare(strict_types=1);

// *** A _minimalist_ php webpage, to be used for load tests. Does nothing and returns an empty page. Useful as baseline for php code.

if (!isset($_SERVER['ROADRUNNER_WORKER']) || (int)$_SERVER['ROADRUNNER_WORKER'] === 0 || PHP_SAPI !== 'cli') {
    throw new \Exception('This script is meant to be used in RoadRunner Worker mode, which is not enabled in the current configuration');
}

// RoadRunner worker mode

require __DIR__ . '/../../../vendor/autoload.php';

use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Worker;

/// @todo... test: does RR actually forward the signals to the workers? If not, we could have the init.d script signal them directly...
if (function_exists('pcntl_signal')) {
    function sigHandler($signo): void
    {
        switch ($signo) {
            case SIGINT:
            case SIGQUIT:
            case SIGTERM:
                // handle shutdown tasks
                exit;
            case SIGHUP:
                /// @todo... reload the config and restart the server
                break;
            default:
                // handle all other signals
        }
    }
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, "sigHandler");
    pcntl_signal(SIGQUIT, "sigHandler");
    pcntl_signal(SIGTERM, "sigHandler");
    pcntl_signal(SIGHUP,  "sigHandler");
}

// Create new RoadRunner worker from global environment
$worker = Worker::create();

$httpWorker = new HttpWorker($worker);

while (true) {
    try {
/// @todo... for a true 'best case scenario' timing, there is no need to deserialize the goridge message into an http one
///          (can we simplify the `$httpWorker->respond` call too?)
        $request = $httpWorker->waitRequest();
        if ($request === null) {
            break;
        }
    } catch (\Throwable $e) {
        // Although the PSR-17 specification clearly states that there can be
        // no exceptions when creating a request, however, some implementations
        // may violate this rule. Therefore, it is recommended to process the
        // incoming request for errors.
        //
        // Send "Bad Request" response.
        $httpWorker->respond(400);
        continue;
    }

    try {
        // Reply with a 200 OK response
        $httpWorker->respond(200);
    } catch (\Throwable $e) {
        // In case of any exceptions in the application code, you should handle
        // them and inform the client about the presence of a server error.
        //
        // Reply by the 500 Internal Server Error response
        $httpWorker->respond(500);

        // Additionally, we can inform the RoadRunner that the processing
        // of the request failed.
        $worker->error((string)$e);
    }
}
