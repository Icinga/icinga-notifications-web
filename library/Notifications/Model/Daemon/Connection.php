<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use React\Socket\ConnectionInterface;
use React\Stream\ThroughStream;
use stdClass;

final class Connection
{
    /**
     * @var ConnectionInterface $connection
     */
    private $connection;

    /**
     * @var string $host
     */
    private $host;

    /**
     * @var int $port
     */
    private $port;

    /**
     * @var string $session
     */
    private $session;

    /**
     * @var User $user
     */
    private $user;

    /**
     * @var ThroughStream $stream
     */
    private $stream;

    /**
     * @var string $browserId
     */
    private $browserId;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;

        if ($connection->getRemoteAddress() !== null) {
            $address = $this->parseHostAndPort($connection->getRemoteAddress());
            $this->host = $address->host;
            $this->port = (int) $address->port;
        } else {
            $this->host = '';
            $this->port = -1;
        }

        $this->stream = new ThroughStream();
        $this->session = '';
        $this->user = new User();
        $this->browserId = '';
    }

    public static function parseHostAndPort(string $address): stdClass
    {
        $raw = $address;
        $combined = new stdClass();
        $combined->host = substr(
            $raw,
            strpos($raw, '[') + 1,
            strpos($raw, ']') - (strpos($raw, '[') + 1)
        );
        if (strpos($combined->host, '.')) {
            // it's an IPv4, stripping empty IPv6 tags
            $combined->host = substr($combined->host, strrpos($combined->host, ':') + 1);
        }
        $combined->port = substr($raw, strpos($raw, ']') + 2);
        $combined->addr = $combined->host . ':' . $combined->port;

        return $combined;
    }

    public static function calculateBrowserId(string $userAgent, string $user): ?string
    {
        if (in_array('joaat', hash_algos())) {
            if (strlen(trim($userAgent)) > 0 && strlen(trim($user)) > 0) {
                return strtoupper(hash('joaat', $user . trim($userAgent)));
            }
        }

        return null;
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

    public function setSession(string $session): void
    {
        $this->session = $session;
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

    public function getBrowserId(): ?string
    {
        return $this->browserId;
    }

    public function setBrowserId(string $browserId): void
    {
        $this->browserId = $browserId;
    }

    public function sendEvent(Event $event): void
    {
        $this->stream->write(
            $event
        );
    }
}
