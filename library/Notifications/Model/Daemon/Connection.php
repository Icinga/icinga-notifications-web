<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model\Daemon;

use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;

class Connection
{
    /** @var ConnectionInterface Associated Connection from ReactPHP */
    protected $connection;

    /** @var string Hostname */
    protected $host;

    /** @var int Port */
    protected $port;

    /** @var string Session identifier */
    protected $session;

    /** @var User User information */
    protected $user;

    /** @var ThroughStream Data stream between connection and server */
    protected $stream;

    /** @var string User agent */
    protected $userAgent;

    /**
     * Construct an instance of the Connection class
     *
     * @param ConnectionInterface $connection Connection details
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->host = '';
        $this->port = -1;

        if ($connection->getRemoteAddress() !== null) {
            $address = $this->parseHostAndPort($connection->getRemoteAddress());
            if ($address) {
                $this->host = $address->host;
                $this->port = (int) $address->port;
            }
        }

        $this->stream = new ThroughStream();
        $this->session = '';
        $this->user = new User();
        $this->userAgent = '';
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getAddress(): string
    {
        return $this->host . ':' . $this->port;
    }

    public function getSession(): ?string
    {
        return $this->session;
    }

    public function getStream(): ThroughStream
    {
        return $this->stream;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setSession(string $session): void
    {
        $this->session = $session;
    }

    public function setUserAgent(string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    /**
     * @param ?string $address Host address
     *
     * @return object{host: string, port: string, addr: string} | false Host, port and full address or false if the
     * parsing failed
     */
    public static function parseHostAndPort(?string $address)
    {
        if ($address === null) {
            return false;
        }

        $raw = $address;
        $parsed = (object) [
            'host' => '',
            'port' => '',
            'addr' => ''
        ];

        // host
        $host = substr(
            $raw,
            strpos($raw, '[') + 1,
            strpos($raw, ']') - (strpos($raw, '[') + 1)
        );
        if (! $host) {
            return false;
        }

        if (strpos($host, '.')) {
            // it's an IPv4, stripping empty IPv6 tags
            $parsed->host = substr($host, strrpos($host, ':') + 1);
        } else {
            $parsed->host = $host;
        }

        // port
        $port = substr($raw, strpos($raw, ']') + 2);
        if (! $port) {
            return false;
        }

        $parsed->port = $port;
        $parsed->addr = $parsed->host . ':' . $parsed->port;

        return $parsed;
    }

    /**
     * Send an event to the connection
     *
     * @param Event $event Event
     *
     * @return bool if the event could be pushed to the connection stream
     */
    public function sendEvent(Event $event): bool
    {
        return $this->stream->write($event);
    }
}
