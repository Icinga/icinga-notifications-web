<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Date\DateFormatter;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\IncidentList;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\ValidHtml;
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
        //TODO(sd): This is just placeholder. Add hook implementation instead
        $relatedObj = Html::tag('ul', ['class' => ['item-list', 'action-list'], 'data-base-target' => '_next']);
        /** @var Objects $obj */
        $obj = $this->event->object;
        $objLink = new Link($obj->getName(), $obj->url, ['class' => 'subject']);
        $relatedObj->add(
            Html::tag(
                'li',
                ['class' => 'list-item', 'data-action-item' => true],
                [ //TODO(sd): fix stateball
                    Html::tag('div', ['class' => 'visual'], new StateBall('down', StateBall::SIZE_LARGE)),
                    Html::tag(
                        'div',
                        ['class' => 'main'],
                        Html::tag('header')->add(Html::tag('div', ['class' => 'title'], $objLink))
                    )
                ]
            )
        );

        return [
            Html::tag('h2', t('Related Object')),
            $relatedObj
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
