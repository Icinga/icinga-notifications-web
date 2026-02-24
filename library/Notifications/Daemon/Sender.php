<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Daemon;

use Closure;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Model\Daemon\Event;
use Icinga\Module\Notifications\Model\Daemon\EventIdentifier;

class Sender
{
    protected const PREFIX = '[daemon.sender] - ';

    /** @var Sender Instance of this class */
    private static $instance;

    /** @var Logger Instance of the logger class */
    protected static $logger;

    /** @var Daemon Daemon object reference */
    protected static $daemon;

    /** @var Server Server object reference */
    protected static $server;

    /** @var Closure {@see processNotification()} wrapper */
    protected $callback;

    /**
     * Construct the singleton instance of the Sender class
     *
     * @param Daemon $daemon Reference to the Daemon instance
     * @param Server $server Reference to the Server instance
     */
    private function __construct(Daemon &$daemon, Server &$server)
    {
        self::$logger = Logger::getInstance();
        self::$daemon = &$daemon;
        self::$server = &$server;

        self::$logger::info(self::PREFIX . "spawned");

        $this->callback = function ($event) {
            $this->processNotification($event);
        };

        $this->load();
    }

    /**
     * Return the singleton instance of the Daemon class
     *
     * @param Daemon $daemon Reference to the Daemon instance
     * @param Server $server Reference to the Server instance
     *
     * @return Sender Singleton instance
     */
    public static function get(Daemon &$daemon, Server &$server): Sender
    {
        if (self::$instance === null) {
            self::$instance = new Sender($daemon, $server);
        }

        return self::$instance;
    }

    /**
     * Run the loading logic
     *
     * @return void
     */
    public function load(): void
    {
        self::$logger::debug(self::PREFIX . "loading");

        self::$daemon->on(EventIdentifier::ICINGA2_NOTIFICATION, $this->callback);

        self::$logger::debug(self::PREFIX . "loaded");
    }

    /**
     * Run the unloading logic
     *
     * @return void
     */
    public function unload(): void
    {
        self::$logger::debug(self::PREFIX . "unloading");

        self::$daemon->removeListener(EventIdentifier::ICINGA2_NOTIFICATION, $this->callback);

        self::$logger::debug(self::PREFIX . "unloaded");
    }

    /**
     * Run the reloading logic
     *
     * @return void
     */
    public function reload(): void
    {
        self::$logger::debug(self::PREFIX . "reloading");

        $this->unload();
        $this->load();

        self::$logger::debug(self::PREFIX . "reloaded");
    }

    /**
     * Process the given notification and send it to the appropriate clients
     *
     * @param Event $event Notification event
     */
    protected function processNotification(Event $event): void
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
                            if (
                                $c->getUserAgent() === $browserConnection->getUserAgent()
                                && $c !== $browserConnection
                                && $c->sendEvent($event)
                            ) {
                                // fallback connection for this browser exists and the notification delivery succeeded
                                $fallback = true;

                                break;
                            }
                        }

                        if ($fallback === false) {
                            self::$logger::error(
                                self::PREFIX
                                . "failed sending event '" . $event->getIdentifier()
                                . "' to <" . $browserConnection->getAddress() . ">"
                            );
                        }
                    }

                    $notifiedBrowsers[] = $browserConnection->getUserAgent();
                }
            }
        }
    }
}
