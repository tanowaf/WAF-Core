<?php

namespace TanoWAF\WAFCore\Swoole;

use TanoWAF\WAFCore\Exception\ConfigurationError;

class ServerFactory
{
    protected function defaultConfiguration(): array
    {
        return ['server' => ['listen_ip' => '0.0.0.0', 'listen_port' => 8080], 'swoole_server_settings'];
    }

    /**
     * @see https://wiki.swoole.com/en/#/http_server?id=configuration-options
     * @see https://openswoole.com/docs/modules/swoole-server/configuration
     * @throws ConfigurationError
     * @phpstan-ignore class.notFound
     * @noinspection PhpUndefinedClassInspection, PhpUndefinedNamespaceInspection
     */
    public function fromConfig(array $config): \Swoole\Http\Server|\Swoole\Coroutine\Http\Server|\OpenSwoole\Http\Server
    {
        $config = array_replace_recursive($this->defaultConfiguration(), $config);

        if (!isset($config['server']) || !is_array($config['server']) || !isset($config['server_settings']) ||
            !is_array($config['server_settings'])) {
            throw new ConfigurationError("Swoole configuration error: missing either 'server' or 'server_settings' top-level key");
        }

        if (extension_loaded('swoole')) {
            $serverClass = '\Swoole\Http\Server';
        } else if (extension_loaded('openswoole')) {
            $serverClass = '\OpenSwoole\Http\Server';
        } else {
            throw new \Exception("Either the Swoole or OpenSwoole php extension must be active");
        }

        if (isset($config['server']['enable_io-uring']) && $config['server']['enable_io-uring']) {
            if (extension_loaded('swoole')) {
                $serverClass = '\Swoole\Coroutine\Http\Server';
            } else if (extension_loaded('openswoole')) {
                /** @noinspection PhpUndefinedConstantInspection */
                /** @phpstan-ignore constant.notFound */
                \OpenSwoole\Coroutine::set(['reactor_type' => OPENSWOOLE_IO_URING]);
            }
        }

        /// @todo check with other $_SERVER vars we could/should set
        if (array_key_exists('listen_socket', $config['server']) && $config['server']['listen_socket'] != '') {
            if ($serverClass == '\Swoole\Coroutine\Http\Server') {
                throw new ConfigurationError("Swoole configuration error: enable_io-uring is not supported with unix sockets");
            }
            // use integers to simplify working with both swoole and openswoole
            $server = new $serverClass($config['server']['listen_socket'], 0, 1, 5);
        } else {
            $_SERVER['SERVER_ADDR'] = $config['server']['listen_ip'];
            $_SERVER['SERVER_PORT'] = (int)$config['server']['listen_port'];
            $server = new $serverClass($config['server']['listen_ip'], (int)$config['server']['listen_port']);
        }

        $server->set($config['server_settings']);

        return $server;
    }
}
