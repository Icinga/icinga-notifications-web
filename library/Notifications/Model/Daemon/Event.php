<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use DateTime;
use DateTimeInterface;
use Icinga\Util\Json;
use stdClass;

final class Event
{
    /**
     * @var string $identifier
     */
    private $identifier;

    /**
     * @var stdClass $data
     */
    private $data;

    /**
     * @var DateTime $createdAt
     */
    private $createdAt;

    /**
     * @var int $reconnectInterval
     */
    private $reconnectInterval;

    /**
     * @var int $lastEventId
     */
    private $lastEventId;

    final public function __construct(string $identifier, stdClass $data, int $lastEventId = 0)
    {
        $this->identifier = $identifier;
        $this->data = $data;
        $this->reconnectInterval = 3000;
        $this->lastEventId = $lastEventId;

        // TODO: Replace with hrtime(true) once the lowest supported PHP version raises to 7.3
        $this->createdAt = new DateTime();
    }

    final public function getIdentifier(): string
    {
        return $this->identifier;
    }

    final public function getData(): stdClass
    {
        return $this->data;
    }

    final public function getCreatedAt(): string
    {
        return $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    final public function getReconnectInterval(): int
    {
        return $this->reconnectInterval;
    }

    final public function setReconnectInterval(int $reconnectInterval): void
    {
        $this->reconnectInterval = $reconnectInterval;
    }

    final public function getLastEventId(): int
    {
        return $this->lastEventId;
    }

    private function compileMessage(): string
    {
        $payload = (object) [
            'time' => $this->getCreatedAt(),
            'payload' => $this->getData()
        ];

        $message = 'event: ' . $this->identifier . PHP_EOL;
        $message .= 'data: ' . Json::encode($payload) . PHP_EOL;
        $message .= 'id: ' . ($this->getLastEventId() + 1) . PHP_EOL;
        $message .= 'retry: ' . $this->reconnectInterval . PHP_EOL;

        // ending newline
        $message .= PHP_EOL;
        return $message;
    }

    final public function __toString(): string
    {
        // compile event to the appropriate representation for event streams
        return $this->compileMessage();
    }
}
