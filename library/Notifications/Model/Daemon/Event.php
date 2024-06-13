<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model\Daemon;

use DateTime;
use DateTimeInterface;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Util\Json;
use stdClass;

class Event
{
    /** @var string Event identifier */
    protected $identifier;

    /** @var stdClass Event data */
    protected $data;

    /** @var DateTime Creation date of event */
    protected $createdAt;

    /** @var int Reconnect interval in milliseconds */
    protected $reconnectInterval;

    /** @var int Last event identifier */
    protected $lastEventId;

    /** @var int Contact identifier associated with this event */
    protected $contact;

    public function __construct(string $identifier, int $contact, stdClass $data, int $lastEventId = 0)
    {
        $this->identifier = $identifier;
        $this->contact = $contact;
        $this->data = $data;
        $this->reconnectInterval = 3000;
        $this->lastEventId = $lastEventId;

        $this->createdAt = new DateTime();
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getContact(): int
    {
        return $this->contact;
    }

    public function getData(): stdClass
    {
        return $this->data;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    public function getReconnectInterval(): int
    {
        return $this->reconnectInterval;
    }

    public function getLastEventId(): int
    {
        return $this->lastEventId;
    }

    public function setReconnectInterval(int $reconnectInterval): void
    {
        $this->reconnectInterval = $reconnectInterval;
    }

    /**
     * Compile event message according to
     * {@link https://html.spec.whatwg.org/multipage/server-sent-events.html#parsing-an-event-stream SSE Spec}
     *
     * @return string
     * @throws JsonEncodeException
     */
    protected function compileMessage(): string
    {
        $payload = (object) [
            'time'    => $this->getCreatedAt(),
            'payload' => $this->getData()
        ];

        $message = 'event: ' . $this->identifier . PHP_EOL;
        $message .= 'data: ' . Json::encode($payload) . PHP_EOL;
        //$message .= 'id: ' . ($this->getLastEventId() + 1) . PHP_EOL;
        $message .= 'retry: ' . $this->reconnectInterval . PHP_EOL;

        // ending newline
        $message .= PHP_EOL;

        return $message;
    }

    public function __toString(): string
    {
        // compile event to the appropriate representation for event streams
        return $this->compileMessage();
    }
}
