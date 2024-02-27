<?php

namespace Icinga\Module\Notifications\Daemon;

use Closure;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Model\Daemon\Event;
use Icinga\Module\Notifications\Model\Daemon\EventIdentifier;

final class Sender
{
    private const PREFIX = '[daemon.sender] - ';

    /**
     * @var Sender $instance
     */
    private static $instance;

    /**
     * @var Logger $logger
     */
    private static $logger;

    /**
     * @var Daemon $daemon
     */
    private static $daemon;

    /**
     * @var Server $server
     */
    private static $server;

    /**
     * @var Closure $callback
     */
    private $callback;

    final private function __construct(Daemon &$daemon, Server &$server)
    {
        self::$logger = Logger::getInstance();
        self::$daemon =& $daemon;
        self::$server =& $server;

        self::$logger::info(self::PREFIX . "spawned");

        $this->callback = function ($event) {
            $this->processNotification($event);
        };

        $this->load();
    }

    final public function load(): void
    {
        self::$logger::debug(self::PREFIX . "loading");
        self::$daemon->on(EventIdentifier::ICINGA2_NOTIFICATION, $this->callback);
        self::$logger::debug(self::PREFIX . "loaded");
    }

    final public function unload(): void
    {
        self::$logger::debug(self::PREFIX . "unloading");
        self::$daemon->removeListener(EventIdentifier::ICINGA2_NOTIFICATION, $this->callback);
        self::$logger::debug(self::PREFIX . "unloaded");
    }

    /**
     * @param Event $event
     */
    private function processNotification(Event $event): void
    {
        $connections = self::$server->getMatchedConnections();

        // get contact's current connections
        if (array_key_exists($event->getContact(), $connections)) {
            $browserConnections = $connections[$event->getContact()];
            $notifiedBrowsers = [];
            foreach ($browserConnections as $browserConnection) {
                if (in_array($browserConnection->getUserAgent(), $notifiedBrowsers) === false) {
                    // this browser has not been notified yet
                    if ($browserConnection->sendEvent($event) === false) {
                        // writing to the browser stream failed, searching for a fallback connection for this browser
                        $fallback = false;
                        foreach ($browserConnections as $c) {
                            if ($c->getUserAgent() === $browserConnection->getUserAgent(
                                ) && $c !== $browserConnection) {
                                // fallback connection for this browser exists, trying it again
                                if ($c->sendEvent($event)) {
                                    $fallback = true;
                                    break;
                                }
                            }
                        }
                        if ($fallback === false) {
                            self::$logger::error(
                                self::PREFIX .
                                "failed sending event '" . $event->getIdentifier() .
                                "' to <" . $browserConnection->getAddress() . ">"
                            );
                        }
                    }
                    $notifiedBrowsers[] = $browserConnection->getUserAgent();
                }
            }
        }
    }

    final public static function get(Daemon &$daemon, Server &$server): Sender
    {
        if (self::$instance === null) {
            self::$instance = new Sender($daemon, $server);
        }
        return self::$instance;
    }
}
