<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseListItem;
use Icinga\Module\Noma\Common\Links;
use Icinga\Module\Noma\Model\Event;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\ValidHtml;
use ipl\Web\Widget\IcingaIcon;
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

    /** @var EventList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true);
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        switch ($this->item->severity) {
            //TODO(sd): Add icons based on severity
            default:
                $content = new Icon('triangle-exclamation');
                break;
        }

        $visual->addHtml($content);
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(Html::tag('span', [], sprintf('#%d:', $this->item->incident->id)));

        $content = new Link(
            $this->item->object->host,
            Links::event($this->item->id),
            ['class' => 'subject']
        );

        if ($this->item->object->service) {
            $content = Html::sprintf(
                t('%s on %s', '<service> on <host>'),
                $content->setContent($this->item->object->service),
                Html::tag('span', ['class' => 'subject'], $this->item->object->host)
            );
        }

        $title->addHtml($content);
        $title->addHtml(Html::tag(
            'span',
            ['class' => 'state'],
            $this->item->severity ? t('ran into a problem') : t('recovered')
        ));
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
        $header->add($this->createSourceIcon());
        $header->add(new TimeAgo($this->item->time->getTimestamp()));
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
        $main->add($this->createFooter());
    }

    protected function createSourceIcon(): ValidHtml
    {
        $source = $this->item->source;

        $icon = Html::tag('span', ['class' => 'event-source', 'title' => $source->name ?? $source->type]);

        switch ($source->type) {
            //TODO(sd): Add icons for other known sources
            case 'icinga':
                $icon->add(new IcingaIcon('icinga'));
                break;
        }

        return $icon;
    }
}
