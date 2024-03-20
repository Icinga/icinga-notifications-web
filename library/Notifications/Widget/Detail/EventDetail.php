<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Date\DateFormatter;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\IncidentList;
use InvalidArgumentException;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\ValidHtml;
use ipl\Web\Widget\HorizontalKeyValue;

class EventDetail extends BaseHtmlElement
{
    /** @var Event */
    protected $event;

    /** @var Incident */
    protected $incident;

    protected $defaultAttributes = [
        'class'                         => 'event-detail',
        'data-pdfexport-page-breaks-at' => 'h2'
    ];

    protected $tag = 'div';

    public function __construct(Event $event)
    {
        if (! $event->incident instanceof Incident) {
            throw new InvalidArgumentException('Incidents must be loaded with the event');
        }

        $this->event = $event;
        $this->incident = $event->incident;
    }

    /** @return ValidHtml[] */
    protected function createInfo(): array
    {
        $info[] = new HorizontalKeyValue(
            t('Received On'),
            DateFormatter::formatDateTime($this->event->time->getTimestamp())
        );

        $severity = $this->event->getSeverityText();

        if ($severity) {
            $info[] = new HorizontalKeyValue(t('Severity'), $severity);
        }

        return $info;
    }

    /** @return ValidHtml[] */
    protected function createMessage(): array
    {
        return [
            Html::tag('h2', t('Message')),
            Html::tag(
                'div',
                [
                    'id'                    => 'message-' . $this->event->id,
                    'class'                 => 'collapsible',
                    'data-visible-height'   => 100
                ],
                $this->event->message
            )
        ];
    }

    /** @return ValidHtml[] */
    protected function createRelatedObject(): array
    {
        return [
            Html::tag('h2', t('Related Object')),
            ObjectsRendererHook::renderObjectLink($this->event->object)
        ];
    }

    /** @return ValidHtml[]|null */
    protected function createIncident(): ?array
    {
        if ($this->incident->id === null) {
            return null;
        }

        return [
            Html::tag('h2', t('Incident')),
            new IncidentList([$this->incident])
        ];
    }

    /** @return ValidHtml[] */
    protected function createSource(): array
    {
        $elements = [];
        if ($this->event->type === 'internal') {
            // return no source elements for internal events
            return $elements;
        }

        $elements[] = Html::tag('h2', t('Source'));

        $elements[] = new EventSourceBadge($this->event->object->source);

        return $elements;
    }

    protected function assemble()
    {
        $this->add([
            $this->createInfo(),
            $this->createMessage(),
            $this->createRelatedObject(),
            $this->createIncident(),
            $this->createSource()
        ]);
    }
}
