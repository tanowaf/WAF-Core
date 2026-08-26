<?php

namespace TanoWAF\WAFCore\Swoole;

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

        /// @todo check with other $_SERVER vars we could/should set
        if (array_key_exists('listen_socket', $config) && $config['listen_socket'] != '') {
            // use integers to simplify working with both swoole and openswoole
            $server = new $serverClass($config['listen_socket'], 0, 1, 5);
        } else {
            $_SERVER['SERVER_ADDR'] = $config['listen_ip'];
            $_SERVER['SERVER_PORT'] = (int)$config['listen_port'];
            $server = new $serverClass($config['listen_ip'], (int)$config['listen_port']);
        }
        unset($config['listen_ip'], $config['listen_port'], $config['listen_socket']);

        $server->set($config);

        return $server;
    }
}
