<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

trait SwooleAwareTrait
{
    protected null|\OpenSwoole\Http\Request|\Swoole\Http\Request $swooleRequest = null;
    protected null|\OpenSwoole\Http\Response|\Swoole\Http\Response $swooleResponse = null;

    public function setSwooleRequest(\OpenSwoole\Http\Request|\Swoole\Http\Request|null $request): void
    {
        $this->swooleRequest = $request;
    }

    public function setSwooleResponse(\OpenSwoole\Http\Response|\Swoole\Http\Response|null $response): void
    {
        $this->swooleResponse = $response;
    }

    protected function header(string $name, string $value): void
    {
        if ($this->swooleResponse === null) {
            header("$name: $value");
        } else {
            $this->swooleResponse->header($name, $value);
        }
    }

    protected function http_response_code(int $statusCode): void
    {
        if ($this->swooleResponse === null) {
            http_response_code($statusCode);
        } else {
            $this->swooleResponse->status($statusCode);
        }
    }

    protected function echo(string $content): void
    {
        if ($this->swooleResponse === null) {
            echo $content;
        } else {
            // note: according to the docs, using separate write() calls disables support for resp. compression and
            // triggers chunked encoding...
            $this->swooleResponse->write($content);
        }
    }

    protected function envFromSwooleRequest(): void
    {
        if ($this->swooleRequest !== null) {
            if ($this->swooleRequest->cookie !== null) {
                $_COOKIE = $this->swooleRequest->cookie;
            }
            if ($this->swooleRequest->get !== null) {
                $_GET = $this->swooleRequest->get;
            }
            if ($this->swooleRequest->post !== null) {
                $_POST = $this->swooleRequest->post;
            }
            if ($this->swooleRequest->files !== null) {
                $_FILES = $this->swooleRequest->files;
            }
            foreach ($this->swooleRequest->header as $h => $v) {
                $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$h))] = $v;
            }
        }
    }

    protected function cleanUpEnv(): void
    {
        if ($this->swooleRequest !== null) {
            $_COOKIE = [];
            $_GET = [];
            $_POST = [];
            $_FILES = [];
            foreach ($_SERVER as $k => $v ) {
                if (str_starts_with($k, 'HTTP_')) {
                    unset($_SERVER[$k]);
                }
            }
        }
    }
}
