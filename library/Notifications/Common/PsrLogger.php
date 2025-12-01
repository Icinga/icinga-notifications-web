<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use Icinga\Application\Logger as IcingaLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

/**
 * A PSR-3 compliant logger that uses Icinga's logging methods.
 *
 * This logger maps PSR-3 log levels to Icinga's log levels and provides
 * interpolation for context variables in log messages.
 */
class PsrLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * Map PSR-3 levels to Icinga's 4 levels.
     * emergency/alert/critical -> ERROR
     * notice -> INFO
     *
     * @var array<string, string>
     */
    private const MAP = [
        LogLevel::EMERGENCY => 'error',
        LogLevel::ALERT     => 'error',
        LogLevel::CRITICAL  => 'error',
        LogLevel::ERROR     => 'error',
        LogLevel::WARNING   => 'warning',
        LogLevel::NOTICE    => 'info',
        LogLevel::INFO      => 'info',
        LogLevel::DEBUG     => 'debug',
    ];

    /**
     * Logs with an arbitrary level.
     *
     * @param string $level   The log level
     * @param string|Stringable $message The log message
     * @param array  $context Additional context variables to interpolate in the message
     *
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = strtolower((string) $level);
        $icingaMethod = self::MAP[$level] ?? 'debug';

        array_unshift($context, (string) $message);

        switch ($icingaMethod) {
            case 'error':
                IcingaLogger::error(...$context);
                break;
            case 'warning':
                IcingaLogger::warning(...$context);
                break;
            case 'info':
                IcingaLogger::info(...$context);
                break;
            default:
                IcingaLogger::debug(...$context);
                break;
        }
    }
}
