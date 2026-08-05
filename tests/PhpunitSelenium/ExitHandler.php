<?php
declare(strict_types=1);

namespace TanoWAF\WAFCore\Tests\PhpunitSelenium;

/**
 * If Execution is stopped by calling exit();
 * php does not append append.php, so no code coverage data is collected.
 * We have to add shutdown handler to append that file manually.
 * @author Arbuzov <info@whitediver.com>
 */
class ExitHandler
{
    /**
     * Register handler.
     * If project have own shutdown handler user have to add function to handler
     */
    public static function init()
    {
        register_shutdown_function(array(ExitHandler::class, 'handle'));
    }

    /**
     * Manually include appendable files
     */
    public static function handle()
    {
        include_once __DIR__ . '/append.php';

        $execFile = ini_get('auto_append_file');
        if ($execFile !== '') {
            include_once $execFile;
        }
    }
}
