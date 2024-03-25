<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Date\DateFormatter;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
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
                    'id'                  => 'message-' . $this->event->id,
                    'class'               => 'collapsible',
                    'data-visible-height' => 100
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

        /** @var string $objUrl */
        $objUrl = $obj->url;
        $relatedObj->add(
            Html::tag(
                'li',
                ['class' => 'list-item', 'data-action-item' => true],
                [ //TODO(sd): fix stateball
                    Html::tag('div', ['class' => 'visual'], new StateBall('down', StateBall::SIZE_LARGE)),
                    Html::tag(
                        'div',
                        ['class' => 'main'],
                        Html::tag('header')
                            ->add(
                                Html::tag(
                                    'div',
                                    ['class' => 'title'],
                                    new Link($obj->getName(), $objUrl, ['class' => 'subject'])
                                )
                            )
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
        /** @var ?Incident $incident */
        $incident = $this->event->incident;
        if ((! $incident) || (! $incident->id)) {
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
        $elements = [];
        if ($this->event->type === 'internal') {
            // return no source elements for internal events
            return $elements;
        }

        $elements[] = Html::tag('h2', t('Source'));

        /** @var ?Objects $object */
        $object = $this->event->object;
        if ($object) {
            /** @var Source $source */
            $source = $object->source;
            $elements[] = new EventSourceBadge($source);
        }

        return $elements;
    }

    protected function assemble(): void
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
