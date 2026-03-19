<?php
namespace App\Utils;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use App\Config;

/**
 * Logger utility using Monolog
 * Replaces Pino logger from Node.js
 */
class Logger
{
    private static array $loggers = [];

    /**
     * Create or get a named logger instance
     *
     * @param string $name Logger channel name
     * @return MonologLogger
     */
    public static function create(string $name): MonologLogger
    {
        if (isset(self::$loggers[$name])) {
            return self::$loggers[$name];
        }

        $logger = new MonologLogger($name);
        $level = Config::get('isDevelopment') ? MonologLogger::DEBUG : MonologLogger::INFO;

        // Log to file
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $handler = new StreamHandler($logDir . '/app.log', $level);
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        // Also log to stderr for development
        if (Config::get('isDevelopment') && php_sapi_name() === 'cli') {
            $stderrHandler = new StreamHandler('php://stderr', $level);
            $stderrHandler->setFormatter($formatter);
            $logger->pushHandler($stderrHandler);
        }

        self::$loggers[$name] = $logger;
        return $logger;
    }
}
