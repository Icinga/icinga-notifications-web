<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use Exception;

/**
 * TODO: Replace with proper Enum once the lowest supported PHP version raises to 8.1
 */
abstract class EventIdentifier {
    /**
     * @throws Exception
     */
    final private function __construct() {
        throw new Exception("This enum class can't be instantiated.");
    }

    /**
     * @throws Exception
     */
    final public function __call($name, $arguments): void {
        throw new Exception("This enum class can't be called.");
    }

    /**
     * @throws Exception
     */
    final public static function __callStatic($name, $arguments): void {
        throw new Exception("This enum class can't be statically called.");
    }

    /**
     * @throws Exception
     */
    final public function __serialize(): array {
        throw new Exception("This enum class can't be serialized.");
    }

    /**
     * @throws Exception
     */
    final public function __unserialize(array $data): void {
        throw new Exception("This enum class can't be deserialized.");
    }

    /**
     * authentication
     */
    public const AUTH_VALID = 'auth.valid';
    public const AUTH_INVALID = 'auth.invalid';
    public const AUTH_MISSING = 'auth.missing';

    /**
     * connect handling
     */
    public const CONN_RECONNECT = 'conn.reconnect';
    public const CONN_CLOSE = 'conn.close';

    /**
     * miscellaneous
     */
    public const MISC_DUMMY = 'misc.dummy';

    /**
     * motifications
     */
    public const ICINGA2_NOTIFICATION = 'icinga2.notification';
}