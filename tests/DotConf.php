<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests;

/**
 * Use json format for config. Keep, for now, the same injected env vars as we used when relying on dotEnv
 */
class DotConf
{
    protected string $configFile;

    public function __construct(string $configFile = __DIR__ . '/.conf.json')
    {
        $this->configFile = realpath($configFile);
    }

    public function loadEnv(string $serverType, string|null $wafType = null): void
    {
        $config = json_decode(file_get_contents($this->configFile), true);

        if (!isset($config[$serverType])) {
            throw new \RuntimeException("Server type $serverType not configured");
        }

        $_ENV['SERVER_TYPE'] = $serverType;
        foreach($config[$serverType] as $key => $value) {
            if (str_starts_with($key, 'HTTPSERVER_')) {
                $_ENV[$key] = $value;
            }
        }

        if ($wafType == '') {
            $wafType = $serverType;
        }

        if (!isset($config[$wafType])) {
            throw new \RuntimeException("WAF type $wafType not configured");
        }

        $_ENV['WAF_TYPE'] = $wafType;
        foreach($config[$wafType] as $key => $value) {
            if (str_starts_with($key, 'WAF_')) {
                $_ENV[$key] = $value;
            }
        }
    }
}
