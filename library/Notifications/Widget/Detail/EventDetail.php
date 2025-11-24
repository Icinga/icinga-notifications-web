<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Date\DateFormatter;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\IncidentRenderer;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use InvalidArgumentException;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Widget\HorizontalKeyValue;

class EventDetail extends BaseHtmlElement
{
    /** @var Event */
    protected Event $event;

    /** @var Incident */
    protected Incident $incident;

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

        if ($this->event->mute !== null) {
            $info[] = new HorizontalKeyValue(
                t('Muted'),
                $this->event->mute ? t('Yes') : t('No')
            );
        }

        return $info;
    }

    /** @return ValidHtml[] */
    protected function createMessage(): array
    {
        $messages = [];

        if ($this->event->mute_reason !== null) {
            $messages[] = Html::tag('h2', t('Mute Reason'));
            $messages[] = Html::tag(
                'div',
                [
                    'id'                    => 'mute-reason-' . $this->event->id,
                    'class'                 => 'collapsible',
                    'data-visible-height'   => 100
                ],
                $this->event->mute_reason
            );
        }

        if ($this->event->message !== null) {
            $messages[] = Html::tag('h2', t('Message'));
            $messages[] = Html::tag(
                'div',
                [
                    'id'                    => 'message-' . $this->event->id,
                    'class'                 => 'collapsible',
                    'data-visible-height'   => 100
                ],
                $this->event->message
            );
        }

        return $messages;
    }

    /** @return ValidHtml[] */
    protected function createRelatedObject(): array
    {
        $objectUrl = ObjectsRendererHook::renderObjectLink($this->event->object);

        if (! $objectUrl) {
            return [];
        }

        return [
            new HtmlElement('h2', null, Text::create(t('Related Object'))),
            $objectUrl
        ];
    }

    /** @return ValidHtml[]|null */
    protected function createIncident(): ?array
    {
        if ($this->incident->id === null) {
            return null;
        }

        $incidentItem = new MinimalItemLayout($this->incident, new IncidentRenderer());

        return [
            Html::tag('h2', t('Incident')),
            new HtmlElement('div', $incidentItem->getAttributes(), $incidentItem)
        ];
    }

    /** @return ValidHtml[] */
    protected function createSource(): array
    {
        $elements = [];
        if ($this->event->type !== 'state') {
            // return no source elements for non state events
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
