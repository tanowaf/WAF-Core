<?php

namespace TanoWAF\WAFCore\Swoole;

use TanoWAF\WAFCore\Exception\ConfigurationError;

class ServerFactory
{
    /**
     * @see https://wiki.swoole.com/en/#/http_server?id=configuration-options
     * @see https://openswoole.com/docs/modules/swoole-server/configuration
     * @phpstan-ignore class.notFound
     */
    public function fromConfig(array $config): \Swoole\Http\Server|\OpenSwoole\Http\Server
    {
        if (class_exists('\Swoole\Http\Server')) {
            $serverClass = '\Swoole\Http\Server';
        } else if (class_exists('\OpenSwoole\Http\Server')) {
            $serverClass = '\OpenSwoole\Http\Server';
        } else {
            throw new \Exception("Either the Swoole or OpenSwoole php extension must be active");
        }

        if (!isset($config['listen']) || !is_array($config['listen']) || !isset($config['server_settings']) ||
            !is_array($config['server_settings'])) {
            throw new ConfigurationError("Swoole configuration error: missing either 'listen' or 'server_settings' top-level key");
        }

        /// @todo check with other $_SERVER vars we could/should set
        if (array_key_exists('listen_socket', $config['listen']) && $config['listen']['listen_socket'] != '') {
            // use integers to simplify working with both swoole and openswoole
            $server = new $serverClass($config['listen']['listen_socket'], 0, 1, 5);
        } else {
            $_SERVER['SERVER_ADDR'] = $config['listen']['listen_ip'];
            $_SERVER['SERVER_PORT'] = (int)$config['listen']['listen_port'];
            $server = new $serverClass($config['listen']['listen_ip'], (int)$config['listen']['listen_port']);
        }

        $server->set($config['server_settings']);

        return $server;
    }
}
