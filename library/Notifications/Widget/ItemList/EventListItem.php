<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Widget\IconBall;
use Icinga\Module\Notifications\Widget\SourceIcon;
use InvalidArgumentException;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Str;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;

/**
 * Event item of an event list. Represents one database row.
 */
class EventListItem extends BaseListItem
{
    /** @var Event The associated list item */
    protected $item;

    /** @var Incident The related incident */
    protected $incident;

    /** @var EventList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        if (! $this->item->incident instanceof Incident) {
            throw new InvalidArgumentException('Incidents must be loaded with the event');
        } else {
            $this->incident = $this->item->incident;
        }

        if (! $this->list->getNoSubjectLink()) {
            $this->getAttributes()
                ->set('data-action-item', true);
        }
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $icon = $this->item->getIcon();
        if ($icon) {
            $visual->addHtml($icon);
        }
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        if ($this->incident->id !== null) {
            $title->addHtml(Html::tag('span', [], sprintf('#%d:', $this->incident->id)));
        }

        if (! $this->list->getNoSubjectLink()) {
            $content = new Link(null, Links::event($this->item->id));
        } else {
            $content = new HtmlElement('span');
        }

        /** @var Objects $obj */
        $obj = $this->item->object;
        $name = $obj->getName();

        $content->addAttributes($name->getAttributes());
        $content->addFrom($name);

        $title->addHtml($content);
        $title->addHtml(HtmlElement::create('span', ['class' => 'state'], $this->item->getTypeText()));
    }

    protected function assembleCaption(BaseHtmlElement $caption): void
    {
        $caption->add($this->item->message);
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $content = [];
        if ($this->item->type === 'state') {
            /** @var Objects $object */
            $object = $this->item->object;
            /** @var Source $source */
            $source = $object->source;
            $content[] = (new SourceIcon(SourceIcon::SIZE_BIG))->addHtml($source->getIcon());
        }

        $content[] = new TimeAgo($this->item->time->getTimestamp());

        $header->add($this->createTitle());
        $header->add(
            Html::tag(
                'span',
                ['class' => 'meta'],
                $content
            )
        );
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
        $main->add($this->createCaption());
        $main->add($this->createFooter());
    }
}
