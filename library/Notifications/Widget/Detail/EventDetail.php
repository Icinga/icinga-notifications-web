<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Date\DateFormatter;
use Icinga\Module\Notifications\Hook\EventsObjectsInfoHook;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\IncidentList;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\ValidHtml;
use ipl\Web\Url;
use ipl\Web\Widget\HorizontalKeyValue;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\StateBall;

class EventDetail extends BaseHtmlElement
{
    /** @var Event */
    protected $event;

    protected $defaultAttributes = [
        'class'                         => 'event-detail',
        'data-pdfexport-page-breaks-at' => 'h2'
    ];

    protected $tag = 'div';

    public function __construct(Event $event)
    {
        $this->event = $event;
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
        /** @var Objects $obj */
        $obj = $this->event->object;

        /** @var Source $source */
        $source = $obj->source;

        $objWidget = EventsObjectsInfoHook::getObjectListItemWidget($source->name, $obj->id_tags);
        $objUrl = Url::fromPath($obj->url);

        return [
            Html::tag('h2', t('Related Object')),
            $objWidget ?? (new Link(
                $obj->getName(),
                $objUrl->isExternal() ? $objUrl->getAbsoluteUrl() : $objUrl->getRelativeUrl(),
                [
                    'class'            => 'subject'
                ]
            ))->setBaseTarget('_next')
        ];
    }

    /** @return ValidHtml[]|null */
    protected function createIncident(): ?array
    {
        if ($this->event->incident->id === null) {
            return null;
        }

        return [
            Html::tag('h2', t('Incident')),
            new IncidentList([$this->event->incident])
        ];
    }

    /** @return ValidHtml[] */
    protected function createSource(): array
    {
        return [
            Html::tag('h2', t('Source')),
            new EventSourceBadge($this->event->object->source)
        ];
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
