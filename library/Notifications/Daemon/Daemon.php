<?php

namespace Icinga\Module\Notifications\Daemon;

use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Daemon\Event;
use Icinga\Module\Notifications\Model\Daemon\EventIdentifier;
use Icinga\Module\Notifications\Model\Daemon\Session;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\IncidentHistory;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use function Clue\React\Block\await;
use function React\Promise\Timer\sleep;

final class Daemon {
    private const PREFIX = '[daemon] - ';

    /**
     * @var Logger $logger
     */
    private static $logger;

    /**
     * @var Daemon $instance
     */
    private static $instance;

    /**
     * @var LoopInterface $loop
     */
    private $loop;

    /**
     * @var Server $server
     */
    private $server;

    /**
     * @var Connection $database
     */
    private $database;

    /**
     * @var boolean $cancellationToken
     */
    private $cancellationToken;

    /**
     * @var int $initializedAt
     */
    private $initializedAt;

    /**
     * @var int $lastIncidentId
     */
    private $lastIncidentId;

    private function __construct() {
        self::$logger = Logger::getInstance();
        self::$logger::info(self::PREFIX . "spawned");

        $this->load();
    }

    public static function get(): Daemon {
        if(isset(self::$instance) === false) {
            self::$instance = new Daemon();
        }

        return self::$instance;
    }

    private function load(): void {
        self::$logger::debug(self::PREFIX . "loading");

        $this->loop = Loop::get();
        $this->signalHandling($this->loop);
        $this->server = Server::get($this->loop);
        $this->database = Database::get();
        $this->database->connect();
        $this->cancellationToken = false;
        $this->initializedAt = time();
        $this->run();

        self::$logger::debug(self::PREFIX . "loaded");
    }

    public function unload(): void {
        self::$logger::debug(self::PREFIX . "unloading");

        $this->cancellationToken = true;
        $this->database->disconnect();
        $this->server->unload();
        $this->loop->stop();

        unset($this->initializedAt);
        unset($this->database);
        unset($this->server);
        unset($this->loop);

        self::$logger::debug(self::PREFIX . "unloaded");
    }

    public function reload(): void {
        self::$logger::debug(self::PREFIX . "reloading");

        $this->unload();
        $this->load();

        self::$logger::debug(self::PREFIX . "reloaded");
    }

    private function shutdown(bool $isManualShutdown = false): void {
        self::$logger::info(self::PREFIX . "shutting down" . ($isManualShutdown ? " (manually triggered)" : ""));

        $initAt = $this->initializedAt;
        $this->unload();

        self::$logger::info(self::PREFIX . "exited after " . floor((time() - $initAt)) . " seconds");
        exit(0);
    }

    private function signalHandling(LoopInterface $loop): void {
        $reloadFunc = function() {
            $this->reload();
        };
        $exitFunc = function() {
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

    private function housekeeping(): void {
        self::$logger::debug(self::PREFIX . "running housekeeping job");
        $staleSessions = Session::on(Database::get())
            ->filter(Filter::lessThan('authenticated_at', time() - 86400));
        $deletions = 0;

        /** @var Session $session */
        foreach($staleSessions as $session) {
            $this->database->delete(
                'session',
                [
                    'id = ?' => $session->id
                ]
            );
            ++$deletions;
        }

        if($deletions > 0) {
            self::$logger::info(self::PREFIX . "housekeeping cleaned " . $deletions . " stale sessions");
        }
        self::$logger::debug(self::PREFIX . "finished housekeeping job");
    }

    private function processNotifications(): void {
        $numOfNotifications = 0;

        if(isset($this->lastIncidentId) === false) {
            // get the newest incident identifier
            $latestIncidentNotification = IncidentHistory::on(Database::get())
                ->filter(Filter::equal('type', 'notified'))
                ->orderBy('id', 'DESC')
                ->first();
            if($latestIncidentNotification) {
                $this->lastIncidentId = intval($latestIncidentNotification->id);
                self::$logger::debug(self::PREFIX . "fetched latest incident notification identifier: <id: " . $this->lastIncidentId . ">");
            }
        }

        // grab new notifications and the current connections
        $notifications = IncidentHistory::on(Database::get())
            ->filter(Filter::greaterThan('id', $this->lastIncidentId))
            ->filter(Filter::equal('type', 'notified'))
            ->orderBy('id', 'ASC');
        /** @var array<\Icinga\Module\Notifications\Model\Daemon\Connection> $connections */
        $connections = $this->server->getMatchedConnections();

        /** @var IncidentHistory $notification */
        foreach($notifications as $notification) {
            if(isset($connections[$notification->contact_id])) {
                /** @var Incident $incident */
                $incident = IncidentHistory::on(Database::get())
                    ->filter(Filter::equal('id', $notification->caused_by_incident_history_id))
                    ->with([
                        'incident'
                    ])
                    ->first();
                if($incident !== null) {
                    // reformat notification time
                    /** @var DateTime $time */
                    $time = $incident->time;
                    $time->setTimezone(new DateTimeZone('UTC'));
                    $time = $time->format(DateTimeInterface::RFC3339_EXTENDED);

                    $connections[$notification->contact_id]->sendEvent(new Event(
                        EventIdentifier::ICINGA2_NOTIFICATION,
                        (object)[
                            'incident_id' => $incident->incident_id,
                            'event_id' => $incident->event_id,
                            'time' => $time,
                            'severity' => $incident->incident->severity
                        ],
                        // minus one as it's usually expected as an auto-incrementing id, we just want to pass it the actual id in this case
                        intval($notification->id - 1)
                    ));
                    ++$numOfNotifications;
                }
            }

            $this->lastIncidentId = $notification->id;
        }

        if($numOfNotifications > 0) {
            self::$logger::debug(self::PREFIX . "sent " . $numOfNotifications . " notifications");
        }
    }

    private function run(): void {
        $this->loop->futureTick(function() {
            while($this->cancellationToken === false) {
                $beginMs = (int)(microtime(true) * 1000);

                self::$logger::debug(self::PREFIX . "ticking at " . time());
                $this->processNotifications();

                $endMs = (int)(microtime(true) * 1000);
                if(($endMs - $beginMs) < 3000) {
                    // run took less than 3 seconds; sleep for the remaining duration to prevent heavy db loads
                    await(sleep((3000 - ($endMs - $beginMs)) / 1000));
                }
            }
            self::$logger::debug(self::PREFIX . "cancellation triggered; exiting loop");
            $this->shutdown();
        });

        // run housekeeping job every hour
        $this->loop->addPeriodicTimer(3600.0, function() {
            $this->housekeeping();
        });
        // run housekeeping once on daemon start
        $this->loop->futureTick(function() {
            $this->housekeeping();
        });
    }
}