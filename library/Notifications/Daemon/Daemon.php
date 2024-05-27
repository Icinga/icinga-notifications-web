<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Daemon;

use DateTimeInterface;
use DateTimeZone;
use Evenement\EventEmitter;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\BrowserSession;
use Icinga\Module\Notifications\Model\Daemon\Connection;
use Icinga\Module\Notifications\Model\Daemon\Event;
use Icinga\Module\Notifications\Model\Daemon\EventIdentifier;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Model\ObjectIdTag;
use ipl\Sql\Connection as SQLConnection;
use ipl\Stdlib\Filter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

use function Clue\React\Block\await;
use function React\Promise\Timer\sleep;

class Daemon extends EventEmitter
{
    protected const PREFIX = '[daemon] - ';

    /** @var Logger Instance of the logger class */
    protected static $logger;

    /** @var Daemon Instance of this class */
    private static $instance;

    /** @var LoopInterface Main loop */
    protected $loop;

    /** @var Server Server object */
    protected $server;

    /** @var Sender Sender object */
    protected $sender;

    /** @var SQLConnection Database object */
    protected $database;

    /** @var bool Token which can be triggered to exit the main routine */
    protected $cancellationToken;

    /** @var int Timestamp holding the creation's time of this {@see self::$instance instance} */
    protected $initializedAt;

    /** @var int Last checked incident identifier */
    protected $lastIncidentId;

    /**
     * Construct the singleton instance of the Daemon class
     */
    private function __construct()
    {
        self::$logger = Logger::getInstance();
        self::$logger::info(self::PREFIX . "spawned");

        $this->load();
    }

    /**
     * Return the singleton instance of the Daemon class
     *
     * @return Daemon Singleton instance
     */
    public static function get(): Daemon
    {
        if (self::$instance === null) {
            self::$instance = new Daemon();
        }

        return self::$instance;
    }

    /**
     * Run the loading logic
     *
     * @return void
     */
    protected function load(): void
    {
        self::$logger::debug(self::PREFIX . "loading");

        $this->loop = Loop::get();
        $this->signalHandling($this->loop);
        $this->server = Server::get($this->loop);
        $this->sender = Sender::get($this, $this->server);
        $this->database = Database::get();

        $this->database->connect();

        $this->cancellationToken = false;
        $this->initializedAt = time();

        $this->run();

        self::$logger::debug(self::PREFIX . "loaded");
    }

    /**
     * Run the unloading logic
     *
     * @return void
     */
    protected function unload(): void
    {
        self::$logger::debug(self::PREFIX . "unloading");

        $this->cancellationToken = true;

        $this->database->disconnect();
        $this->server->unload();
        $this->sender->unload();
        $this->loop->stop();

        unset($this->initializedAt);
        unset($this->database);
        unset($this->server);
        unset($this->sender);
        unset($this->loop);

        self::$logger::debug(self::PREFIX . "unloaded");
    }

    /**
     * Run the reloading logic
     *
     * @return void
     */
    protected function reload(): void
    {
        self::$logger::debug(self::PREFIX . "reloading");

        $this->unload();
        $this->load();

        self::$logger::debug(self::PREFIX . "reloaded");
    }

    /**
     * Unload the class object and exit the script
     *
     * @param bool $isManualShutdown manual trigger for the shutdown
     *
     * @return never-return
     */
    protected function shutdown(bool $isManualShutdown = false)
    {
        self::$logger::info(self::PREFIX . "shutting down" . ($isManualShutdown ? " (manually triggered)" : ""));

        $initAt = $this->initializedAt;
        $this->unload();

        self::$logger::info(self::PREFIX . "exited after " . floor((time() - $initAt)) . " seconds");
        exit(0);
    }

    /**
     * (Re-)Attach to process exit signals and call the shutdown logic
     *
     * @param LoopInterface $loop ReactPHP's main loop
     *
     * @return void
     */
    protected function signalHandling(LoopInterface $loop): void
    {
        $reloadFunc = function () {
            $this->reload();
        };

        $exitFunc = function () {
            $this->shutdown(true);
        };

        // clear existing signal handlers
        $loop->removeSignal(SIGHUP, $reloadFunc);
        $loop->removeSignal(SIGINT, $exitFunc);
        $loop->removeSignal(SIGQUIT, $exitFunc);
        $loop->removeSignal(SIGTERM, $exitFunc);

        // add new signal handlers
        $loop->addSignal(SIGHUP, $reloadFunc);
        $loop->addSignal(SIGINT, $exitFunc);
        $loop->addSignal(SIGQUIT, $exitFunc);
        $loop->addSignal(SIGTERM, $exitFunc);
    }

    /**
     * Clean up old sessions in the database
     *
     * @return void
     */
    protected function housekeeping(): void
    {
        self::$logger::debug(self::PREFIX . "running housekeeping job");

        $staleBrowserSessions = BrowserSession::on(Database::get())
            ->filter(Filter::lessThan('authenticated_at', time() - 86400));
        $deletions = 0;

        /** @var BrowserSession $session */
        foreach ($staleBrowserSessions as $session) {
            $this->database->delete('browser_session', ['php_session_id = ?' => $session->php_session_id]);
            ++$deletions;
        }

        if ($deletions > 0) {
            self::$logger::info(self::PREFIX . "housekeeping cleaned " . $deletions . " stale browser sessions");
        }

        self::$logger::debug(self::PREFIX . "finished housekeeping job");
    }

    /**
     * Process new notifications (if there are any)
     *
     * @return void
     */
    protected function processNotifications(): void
    {
        $numOfNotifications = 0;

        if ($this->lastIncidentId === null) {
            // get the newest incident identifier
            /** @var IncidentHistory $latestIncidentNotification */
            $latestIncidentNotification = IncidentHistory::on(Database::get())
                ->filter(Filter::equal('type', 'notified'))
                ->orderBy('id', 'DESC')
                ->first();
            if (! $latestIncidentNotification) {
                // early return as we don't need to check for new entries if we don't have any at all
                return;
            }

            $this->lastIncidentId = $latestIncidentNotification->id;
            self::$logger::debug(
                self::PREFIX
                . "fetched latest incident notification identifier: <id: "
                . $this->lastIncidentId
                . ">"
            );
        }

        // grab new notifications and the current connections
        $notifications = IncidentHistory::on(Database::get())
            ->filter(Filter::greaterThan('id', $this->lastIncidentId))
            ->filter(Filter::equal('type', 'notified'))
            ->filter(Filter::equal('notification_state', 'sent'))
            ->orderBy('id', 'ASC')
            ->with(['incident', 'incident.object']);
        /** @var array<int, array<Connection>> $connections */
        $connections = $this->server->getMatchedConnections();

        /** @var IncidentHistory $notification */
        foreach ($notifications as $notification) {
            if (isset($connections[$notification->contact_id])) {
                /** @var Incident $incident */
                $incident = $notification->incident;

                $tags = null;
                /** @var ObjectIdTag $tag */
                foreach ($incident->object->object_id_tag as $tag) {
                    $tags[] = $tag;
                }

                if ($tags !== null) {
                    $host = $service = $message = '';

                    foreach ($tags as $tag) {
                        switch ($tag->tag) {
                            case 'host':
                                $host = $tag->value;
                                $message = "Host: " . $host;

                                break;
                            case 'service':
                                $service = $tag->value;
                                $message .= ($message === '' ? "Service: " : " | Service: ") . $service;

                                break;
                        }
                    }

                    self::$logger::warning(self::PREFIX . $message);

                    // reformat notification time
                    $time = $notification->time;
                    $time->setTimezone(new DateTimeZone('UTC'));
                    $time = $time->format(DateTimeInterface::RFC3339_EXTENDED);

                    $event = new Event(
                        EventIdentifier::ICINGA2_NOTIFICATION,
                        $notification->contact_id,
                        (object) [
                            'incident_id' => $notification->incident_id,
                            'event_id'    => $notification->event_id,
                            'host'        => $host,
                            'service'     => $service,
                            'time'        => $time,
                            'severity'    => $incident->severity
                        ]
                    );

                    $this->emit(EventIdentifier::ICINGA2_NOTIFICATION, [$event]);

                    ++$numOfNotifications;
                }
            }

            $this->lastIncidentId = $notification->id;
        }

        if ($numOfNotifications > 0) {
            self::$logger::debug(self::PREFIX . "sent " . $numOfNotifications . " notifications");
        }
    }

    /**
     * Run main logic
     *
     * This method registers the needed Daemon routines on PhpReact's {@link Loop main loop}.
     * It adds a cancellable infinite loop, which processes new database entries (notifications) every 3 seconds.
     * In addition, a cleanup routine gets registered, which cleans up stale browser sessions each hour if they are
     * older than a day.
     *
     * @return void
     */
    protected function run(): void
    {
        $this->loop->futureTick(function () {
            while ($this->cancellationToken === false) {
                $beginMs = (int) (microtime(true) * 1000);

                self::$logger::debug(self::PREFIX . "ticking at " . time());
                $this->processNotifications();

                $endMs = (int) (microtime(true) * 1000);
                if (($endMs - $beginMs) < 3000) {
                    // run took less than 3 seconds; sleep for the remaining duration to prevent heavy db loads
                    await(sleep((3000 - ($endMs - $beginMs)) / 1000));
                }
            }
            self::$logger::debug(self::PREFIX . "cancellation triggered; exiting loop");
            $this->shutdown();
        });

        // run housekeeping job every hour
        $this->loop->addPeriodicTimer(3600.0, function () {
            $this->housekeeping();
        });
        // run housekeeping once on daemon start
        $this->loop->futureTick(function () {
            $this->housekeeping();
        });
    }
}
