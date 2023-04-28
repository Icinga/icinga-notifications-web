<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\Detail;

use Icinga\Date\DateFormatter;
use Icinga\Module\Noma\Model\Event;
use Icinga\Module\Noma\Widget\EventSourceBadge;
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
        $obj = new Link($this->event->object->host, $this->event->source_object->url, ['class' => 'subject']);

        if ($this->event->object->service) {
            $obj = Html::sprintf(
                t('%s on %s', '<service> on <host>'),
                $obj->setContent($this->event->object->service),
                Html::tag('span', ['class' => 'subject'], $this->event->object->host)
            );
        }

        $relatedObj->add(
            Html::tag(
                'li',
                ['class' => 'list-item', 'data-action-item' => true],
                [ //TODO(sd): fix stateball
                    Html::tag('div', ['class' => 'visual'], new StateBall('down', StateBall::SIZE_LARGE)),
                    Html::tag(
                        'div',
                        ['class' => 'main'],
                        Html::tag('header')->add(Html::tag('div', ['class' => 'title'], $obj))
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
        $incidentId = $this->event->incident->id;
        if ($incidentId === null) {
            return null;
        }

        return [
            Html::tag('h2', t('Incident')),
            sprintf('#%s Incident Item Placeholder', $incidentId)
        ];
    }

    /** @return ValidHtml[] */
    protected function createSource(): array
    {
        return [
            Html::tag('h2', t('Source')),
            new EventSourceBadge($this->event->source)
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