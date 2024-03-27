<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use Exception;

/**
 * TODO: Replace with proper Enum once the lowest supported PHP version raises to 8.1
 */
abstract class EventIdentifier
{
    /**
     * @throws Exception
     */
    final private function __construct()
    {
        throw new Exception("This enum class can't be instantiated.");
    }

    /**
     * @throws Exception
     * @param string $name
     * @param array<object> $arguments
     */
    final public function __call(string $name, array $arguments): void
    {
        throw new Exception("This enum class can't be called.");
    }

    /**
     * @throws Exception
     * @param string $name
     * @param array<object> $arguments
     */
    final public static function __callStatic(string $name, array $arguments): void
    {
        throw new Exception("This enum class can't be statically called.");
    }

    /**
     * @throws Exception
     */
    final public function __serialize(): array
    {
        throw new Exception("This enum class can't be serialized.");
    }

    /**
     * @throws Exception
     * @param array<object> $data
     */
    final public function __unserialize(array $data): void
    {
        throw new Exception("This enum class can't be deserialized.");
    }

    /**
     * notifications
     */
    public const ICINGA2_NOTIFICATION = 'icinga2.notification';
}
