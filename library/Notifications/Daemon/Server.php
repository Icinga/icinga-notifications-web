<?php

namespace Icinga\Module\Notifications\Daemon;

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Daemon\Connection;
use Icinga\Module\Notifications\Model\Daemon\Session;
use ipl\Stdlib\Filter;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use stdClass;

final class Server
{
    private const PREFIX = '[daemon.server] - ';

    /**
     * @var Server $instance
     */
    private static $instance;

    /**
     * @var LoopInterface $mainLoop
     */
    private $mainLoop;

    /**
     * @var Logger $logger
     */
    private static $logger;

    /**
     * @var SocketServer $socket
     */
    private $socket;

    /**
     * @var HttpServer $http
     */
    private $http;

    /**
     * @var array<Connection>
     */
    private $connections;

    /**
     * @var \ipl\Sql\Connection
     */
    private $dbLink;

    private function __construct(LoopInterface $mainLoop)
    {
        self::$logger = Logger::getInstance();
        self::$logger::debug(self::PREFIX . "spawned");

        $this->mainLoop = $mainLoop;
        $this->dbLink = Database::get();

        $this->load();
    }

    public static function get(LoopInterface $mainLoop): Server
    {
        if (self::$instance === null) {
            self::$instance = new Server($mainLoop);
        } elseif ((self::$instance->mainLoop !== null) && (self::$instance->mainLoop !== $mainLoop)) {
            // main loop changed, reloading daemon server
            self::$instance->mainLoop = $mainLoop;
            self::$instance->reload();
        }
        return self::$instance;
    }

    private function load(): void
    {
        self::$logger::debug(self::PREFIX . "loading");

        $this->connections = [];
        $this->socket = new SocketServer('[::]:9001', [], $this->mainLoop);
        $this->http = new HttpServer(function (ServerRequestInterface $request) {
            return $this->handleRequest($request);
        });
        // subscribe to socket events
        $this->socket->on('connection', function (ConnectionInterface $connection) {
            $this->onSocketConnection($connection);
        });
        $this->socket->on('error', function (Exception $error) {
            $this->onSocketError($error);
        });
        // attach http server to socket
        $this->http->listen($this->socket);

        self::$logger::debug(self::PREFIX . "loaded");
    }

    public function unload(): void
    {
        self::$logger::debug(self::PREFIX . "unloading");

        $this->socket->close();

        unset($this->http);
        unset($this->socket);
        unset($this->connections);

        self::$logger::debug(self::PREFIX . "unloaded");
    }

    public function reload(): void
    {
        self::$logger::debug(self::PREFIX . "reloading");

        $this->unload();
        $this->load();

        self::$logger::debug(self::PREFIX . "reloaded");
    }

    private function mapRequestToConnection(ServerRequestInterface $request): ?Connection
    {
        $params = $request->getServerParams();
        if (isset($params['REMOTE_ADDR']) && isset($params['REMOTE_PORT'])) {
            $address = Connection::parseHostAndPort($params['REMOTE_ADDR'] . ':' . $params['REMOTE_PORT']);
            foreach ($this->connections as $connection) {
                if ($connection->getAddress() === $address->addr) {
                    return $connection;
                }
            }
        }
        return null;
    }

    private function onSocketConnection(ConnectionInterface $connection): void
    {
        if ($connection->getRemoteAddress() !== null) {
            $address = Connection::parseHostAndPort($connection->getRemoteAddress());

            // subscribe to events on this connection
            $connection->on('data', function ($data) use ($connection) {
                $this->onConnectionData($connection, $data);
            });
            $connection->on('end', function () use ($connection) {
                $this->onConnectionEnd($connection);
            });
            $connection->on('error', function ($error) use ($connection) {
                $this->onConnectionError($connection, $error);
            });
            $connection->on('close', function () use ($connection) {
                $this->onConnectionClose($connection);
            });

            // keep track of this connection
            self::$logger::debug(self::PREFIX . "<" . $address->addr . "> adding connection to connection pool");
            $this->connections[$address->addr] = new Connection($connection);
        } else {
            self::$logger::warning(self::PREFIX . "failed adding connection as the remote address was empty");
        }
    }

    private function onSocketError(Exception $error): void
    {
        // TODO: ADD error handling
    }

    private function onConnectionData(ConnectionInterface $connection, string $data): void
    {
    }

    private function onConnectionEnd(ConnectionInterface $connection): void
    {
    }

    private function onConnectionError(ConnectionInterface $connection, Exception $error): void
    {
    }

    private function onConnectionClose(ConnectionInterface $connection): void
    {
        // delete the reference to this connection if we have been actively tracking it
        if ($connection->getRemoteAddress() !== null) {
            $address = Connection::parseHostAndPort($connection->getRemoteAddress());
            if (isset($this->connections[$address->addr])) {
                self::$logger::debug(
                    self::PREFIX . "<" . $address->addr . "> removing connection from connection pool"
                );
                unset($this->connections[$address->addr]);
            }
        } else {
            self::$logger::warning(self::PREFIX . "failed removing connection as the remote address was empty");
        }
    }

    private function handleRequest(ServerRequestInterface $request): Response
    {
        // try to map the request to a socket connection
        $connection = $this->mapRequestToConnection($request);
        if ($connection === null) {
            $params = $request->getServerParams();
            $address = (object) array(
                'host' => '',
                'port' => '',
                'addr' => ''
            );
            if (isset($params['REMOTE_ADDR']) && isset($params['REMOTE_PORT'])) {
                $address = Connection::parseHostAndPort($params['REMOTE_ADDR'] . ':' . $params['REMOTE_PORT']);
            }

            self::$logger::warning(
                self::PREFIX
                . ($address->addr !== '' ? ("<" . $address->addr . "> ") : '')
                . "failed matching HTTP request to a tracked connection"
            );
            return new Response(
                StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
                [
                    "Content-Type" => "text/plain",
                    "Cache-Control" => "no-cache"
                ],
                ''
            );
        }

        // request is mapped to an active socket connection; try to authenticate the request
        $authData = $this->authenticate($connection, $request->getCookieParams(), $request->getHeaders());
        if (isset($authData->isValid) && $authData->isValid === false) {
            // authentication failed
            self::$logger::warning(
                self::PREFIX . "<" . $connection->getAddress() . "> failed the authentication. Denying the request"
            );
            return new Response(
            // returning 204 to stop the service-worker from reconnecting
            // see https://javascript.info/server-sent-events#reconnection
                StatusCodeInterface::STATUS_NO_CONTENT,
                [
                    "Content-Type" => "text/plain",
                    "Cache-Control" => "no-cache"
                ],
                ''
            );
        }

        self::$logger::debug(self::PREFIX . "<" . $connection->getAddress() . "> succeeded the authentication");

        // try to match the authenticated connection to a notification contact
        $contactId = $this->matchContact($connection->getUser()->getUsername());
        if ($contactId === null) {
            self::$logger::warning(
                self::PREFIX . "<" . $connection->getAddress() . "> could not match user " . $connection->getUser(
                )->getUsername() . " to an existing notification contact. Denying the request"
            );
            return new Response(
            // returning 204 to stop the service-worker from reconnecting
            // see https://javascript.info/server-sent-events#reconnection
                StatusCodeInterface::STATUS_NO_CONTENT,
                [
                    "Content-Type" => "text/plain",
                    "Cache-Control" => "no-cache"
                ],
                ''
            );
        }

        // save matched contact identifier to user
        $connection->getUser()->setContactId($contactId);
        self::$logger::debug(
            self::PREFIX . "<" . $connection->getAddress() . "> matched connection to contact " . $connection->getUser(
            )->getUsername() . " <id: " . $connection->getUser()->getContactId() . ">"
        );

        // request is valid and matching, returning the corresponding event stream
        self::$logger::info(
            self::PREFIX . "<" . $connection->getAddress(
            ) . "> request is authenticated and matches a proper notification user"
        );
        return new Response(
            StatusCodeInterface::STATUS_OK,
            [
                "Content-Type" => "text/event-stream; charset=utf-8",
                "Cache-Control" => "no-cache",
                "X-Accel-Buffering" => "no"
            ],
            $connection->getStream()
        );
    }

    /**
     * @param Connection $connection
     * @param array<array<string>> $cookies
     * @param array<array<string>> $headers
     * @return stdClass
     */
    private function authenticate(Connection $connection, array $cookies, array $headers): stdClass
    {
        $data = new stdClass();

        if (array_key_exists('Icingaweb2', $cookies)) {
            // session id is supplied, check for the existence of a user-agent header as it's needed to calculate
            // the device id
            if (array_key_exists('User-Agent', $headers) && sizeof($headers['User-Agent']) === 1) {
                // grab session
                /** @var Session $session */
                $session = Session::on($this->dbLink)
                    ->filter(Filter::equal('id', htmlspecialchars(trim($cookies['Icingaweb2'][0]))))
                    ->first();

                // calculate device id
                $deviceId = Connection::calculateDeviceId($headers['User-Agent'][0], $session->username) ?: 'default';

                // check if device id of connection corresponds to device id of authenticated session
                if ($deviceId === $session->device_id) {
                    // making sure that it's the latest session
                    $latestSession = Session::on($this->dbLink)
                        ->filter(Filter::equal('username', $session->username))
                        ->filter(Filter::equal('device_id', $session->device_id))
                        ->orderBy('authenticated_at', 'DESC')
                        ->first();
                    if (isset($latestSession) && ($latestSession->id === $session->id)) {
                        // current session is the latest session for this user and device => this is a valid request
                        $data->session_id = $session->id;
                        $data->user = $session->username;
                        $data->device_id = $session->device_id;
                        $connection->setSession($data->session_id);
                        $connection->getUser()->setUsername($data->user);
                        $connection->setDeviceId($data->device_id);
                        $data->isValid = true;
                        return $data;
                    }
                }
            }
        }

        // the request is invalid, return this result
        $data->isValid = false;
        return $data;
    }

    private function matchContact(?string $username): ?int
    {
        /**
         * TODO: the matching needs to be properly rewritten once we decide about how we want to handle the contacts
         *  in the notifications module
         */
        if ($username !== null) {
            /** @var Contact $contact */
            $contact = Contact::on(Database::get())
                ->filter(Filter::equal('username', $username))
                ->first();
            if ($contact !== null) {
                return $contact->id;
            }
        }
        return null;
    }

    /**
     * @return array<Connection>
     */
    public function getMatchedConnections(): array
    {
        $connections = [];
        foreach ($this->connections as $connection) {
            $contactId = $connection->getUser()->getContactId();
            if (isset($contactId)) {
                $connections[$contactId] = $connection;
            }
        }

        return $connections;
    }
}
