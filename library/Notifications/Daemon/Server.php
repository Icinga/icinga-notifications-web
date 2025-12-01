<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Daemon;

use Fig\Http\Message\StatusCodeInterface;
use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\BrowserSession;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Daemon\Connection;
use ipl\Sql\Connection as SQLConnection;
use ipl\Stdlib\Filter;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Throwable;

class Server
{
    /** @var string */
    protected const PREFIX = '[daemon.server] - ';

    /** @var ?Server Instance of this class */
    private static ?Server $instance = null;

    /** @var ?LoopInterface Reference to ReactPHP's main loop */
    protected ?LoopInterface $mainLoop = null;

    /** @var Logger Instance of the logger class */
    protected static Logger $logger;

    /** @var SocketServer SocketServer object */
    protected SocketServer $socket;

    /** @var HttpServer HttpServer object */
    protected HttpServer $http;

    /** @var array<Connection> Socket connections */
    protected array $connections;

    /** @var SQLConnection Database object */
    protected SQLConnection $dbLink;

    /** @var Config Config object */
    protected Config $config;

    /**
     * Construct the singleton instance of the Server class
     *
     * @param LoopInterface $mainLoop Reference to ReactPHP's main loop
     */
    private function __construct(LoopInterface &$mainLoop)
    {
        self::$logger = Logger::getInstance();
        self::$logger::debug(self::PREFIX . "spawned");

        $this->mainLoop = &$mainLoop;
        $this->dbLink = Database::get();
        $this->config = Config::module('notifications');

        $this->load();
    }

    /**
     * Return the singleton instance of the Server class
     *
     * @param LoopInterface $mainLoop Reference to ReactPHP's main loop
     *
     * @return Server Singleton instance
     */
    public static function get(LoopInterface &$mainLoop): Server
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

    /**
     * Run the loading logic
     *
     * @return void
     */
    public function load(): void
    {
        self::$logger::debug(self::PREFIX . "loading");

        $this->connections = [];
        $this->socket = new SocketServer(
            $this->config->get('daemon', 'host', '127.0.0.1')
            . ':'
            . $this->config->get('daemon', 'port', '5664'),
            [],
            $this->mainLoop
        );
        $this->http = new HttpServer(function (ServerRequestInterface $request) {
            return $this->handleRequest($request);
        });
        $this->http->on('error', function (Throwable $error) {
            self::$logger::error(self::PREFIX . "received an error on the http server: %s", $error);
        });
        // subscribe to socket events
        $this->socket->on('connection', function (ConnectionInterface $connection) {
            $this->onSocketConnection($connection);
        });
        $this->socket->on('error', function (Throwable $error) {
            self::$logger::error(self::PREFIX . "received an error on the socket: %s", $error);
        });
        // attach http server to socket
        $this->http->listen($this->socket);

        self::$logger::info(
            self::PREFIX
            . "listening on "
            . parse_url($this->socket->getAddress() ?? '', PHP_URL_HOST)
            . ':'
            . parse_url($this->socket->getAddress() ?? '', PHP_URL_PORT)
        );

        // add keepalive routine to prevent connection aborts by proxies (Nginx, Apache) or browser restrictions (like
        // the Fetch API on Mozilla Firefox)
        // https://html.spec.whatwg.org/multipage/server-sent-events.html#authoring-notes
        $this->mainLoop->addPeriodicTimer(30.0, function () {
            $this->keepalive();
        });

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

        $this->socket->close();

        unset($this->http);
        unset($this->socket);
        unset($this->connections);

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
     * Map an HTTP(S) request to an already existing socket connection (TCP)
     *
     * @param ServerRequestInterface $request Request to be mapped against a socket connection
     *
     * @return ?Connection Connection object or null if no connection could be mapped against the request
     */
    protected function mapRequestToConnection(ServerRequestInterface $request): ?Connection
    {
        $params = $request->getServerParams();
        $scheme = $request->getUri()->getScheme();

        if (isset($params['REMOTE_ADDR']) && isset($params['REMOTE_PORT'])) {
            $address = Connection::parseHostAndPort(
                $scheme . '://' . $params['REMOTE_ADDR'] . ':' . $params['REMOTE_PORT']
            );
            foreach ($this->connections as $connection) {
                if ($connection->getAddress() === $address->addr) {
                    return $connection;
                }
            }
        }

        return null;
    }

    /**
     * Emit method for socket connections events
     *
     * @param ConnectionInterface $connection Connection details
     *
     * @return void
     */
    protected function onSocketConnection(ConnectionInterface $connection): void
    {
        if ($connection->getRemoteAddress() !== null) {
            $address = Connection::parseHostAndPort($connection->getRemoteAddress());

            // subscribe to events on this connection
            $connection->on('close', function () use ($connection) {
                $this->onSocketConnectionClose($connection);
            });

            // keep track of this connection
            self::$logger::debug(self::PREFIX . "<" . $address->addr . "> adding connection to connection pool");
            $this->connections[$address->addr] = new Connection($connection);
        } else {
            self::$logger::warning(self::PREFIX . "failed adding connection as the remote address was empty");
        }
    }


    /**
     * Emit method for socket connection close events
     *
     * @param ConnectionInterface $connection Connection details
     *
     * @return void
     */
    protected function onSocketConnectionClose(ConnectionInterface $connection): void
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

    /**
     * Handle the request and return an event-stream if the authentication succeeds
     *
     * @param ServerRequestInterface $request Request to be processed
     *
     * @return Response HTTP response (event-stream on success, status 204/500 otherwise)
     */
    protected function handleRequest(ServerRequestInterface $request): Response
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
                    "Content-Type"  => "text/plain",
                    "Cache-Control" => "no-cache"
                ],
                ''
            );
        }

        $version = $request->getHeader('X-Icinga-Notifications-Protocol-Version')[0] ?? 1;
        self::$logger::debug(
            self::PREFIX
            . "<"
            . $connection->getAddress()
            . "> received a request with protocol version "
            . $version
        );

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
                    "Content-Type"  => "text/plain",
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
                self::PREFIX
                . "<"
                . $connection->getAddress()
                . "> could not match user "
                . $connection->getUser()->getUsername()
                . " to an existing notification contact. Denying the request"
            );
            return new Response(
            // returning 204 to stop the service-worker from reconnecting
            // see https://javascript.info/server-sent-events#reconnection
                StatusCodeInterface::STATUS_NO_CONTENT,
                [
                    "Content-Type"  => "text/plain",
                    "Cache-Control" => "no-cache"
                ],
                ''
            );
        }

        // save matched contact identifier to user
        $connection->getUser()->setContactId($contactId);
        self::$logger::debug(
            self::PREFIX
            . "<"
            . $connection->getAddress()
            . "> matched connection to contact "
            . $connection->getUser()->getUsername()
            . " <id: "
            . $connection->getUser()->getContactId()
            . ">"
        );

        // request is valid and matching, returning the corresponding event stream
        self::$logger::info(
            self::PREFIX
            . "<"
            . $connection->getAddress()
            . "> request is authenticated and matches a proper notification user"
        );

        // schedule initial keep-alive
        $this->mainLoop->addTimer(1.0, function () use ($connection) {
            $connection->getStream()->write(':' . PHP_EOL . PHP_EOL);
        });

        // return stream
        return new Response(
            StatusCodeInterface::STATUS_OK,
            [
                "Connection"        => "keep-alive",
                "Content-Type"      => "text/event-stream; charset=utf-8",
                "Cache-Control"     => "no-cache",
                "X-Accel-Buffering" => "no"
            ],
            $connection->getStream()
        );
    }

    /**
     * @param Connection $connection
     * @param array<string> $cookies
     * @param array<array<string>> $headers
     *
     * @return object{isValid: bool, php_session_id: ?string, user: ?string, user_agent: ?string}
     */
    protected function authenticate(Connection $connection, array $cookies, array $headers): object
    {
        $data = (object) [
            'isValid'        => false,
            'php_session_id' => null,
            'user'           => null,
            'user_agent'     => null
        ];

        if (! array_key_exists('Icingaweb2', $cookies)) {
            // early return as the authentication needs the Icingaweb2 session token
            return $data;
        }

        // session id is supplied, check for the existence of a user-agent header as it's needed to calculate
        // the browser id
        if (array_key_exists('User-Agent', $headers) && sizeof($headers['User-Agent']) === 1) {
            // grab session
            /** @var BrowserSession $browserSession */
            $browserSession = BrowserSession::on($this->dbLink)
                ->filter(Filter::equal('php_session_id', htmlspecialchars(trim($cookies['Icingaweb2']))))
                ->first();

            if ($browserSession !== null) {
                if (isset($headers['User-Agent'][0])) {
                    $userAgent = trim($headers['User-Agent'][0]);
                } else {
                    $userAgent = 'default';
                }

                // check if user agent of connection corresponds to user agent of authenticated session
                if ($userAgent === $browserSession->user_agent) {
                    // making sure that it's the latest browser session
                    /** @var BrowserSession $latestSession */
                    $latestSession = BrowserSession::on($this->dbLink)
                        ->filter(Filter::equal('username', $browserSession->username))
                        ->filter(Filter::equal('user_agent', $browserSession->user_agent))
                        ->orderBy('authenticated_at', 'DESC')
                        ->first();
                    if (isset($latestSession) && ($latestSession->php_session_id === $browserSession->php_session_id)) {
                        // current browser session is the latest session for this user and browser => a valid request
                        $data->php_session_id = $browserSession->php_session_id;
                        $data->user = $browserSession->username;
                        $data->user_agent = $browserSession->user_agent;
                        $connection->setSession($data->php_session_id);
                        $connection->getUser()->setUsername($data->user);
                        $connection->setUserAgent($data->user_agent);
                        $data->isValid = true;

                        return $data;
                    }
                }
            }
        }

        // authentication failed
        return $data;
    }

    /**
     * Send keepalive (empty event message) to all connected clients
     *
     * @return void
     */
    protected function keepalive(): void
    {
        foreach ($this->connections as $connection) {
            $connection->getStream()->write(':' . PHP_EOL . PHP_EOL);
        }
    }

    /**
     * Match a username to a contact identifier
     *
     * @param ?string $username
     *
     * @return ?int contact identifier or null if no contact could be matched
     */
    protected function matchContact(?string $username): ?int
    {
        /**
         * TODO(nc): the matching needs to be properly rewritten once we decide about how we want to handle the contacts
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
     * Return list of contacts and their current connections
     *
     * @return array<int, array<Connection>>
     */
    public function getMatchedConnections(): array
    {
        $connections = [];
        foreach ($this->connections as $connection) {
            $contactId = $connection->getUser()->getContactId();
            if (isset($contactId)) {
                if (isset($connections[$contactId]) === false) {
                    $connections[$contactId] = [];
                }
                $connections[$contactId][] = $connection;
            }
        }

        return $connections;
    }
}
