<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\BaseListItem;
use Icinga\Module\Noma\Common\Links;
use Icinga\Module\Noma\Model\Event;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
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
        if (! $this->list->getNoSubjectLink()) {
            $this->getAttributes()
                ->set('data-action-item', true);
        }
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $content = null;
        $severity = $this->item->severity;
        $class = 'severity-' . $severity;
        switch ($severity) {
            case 'ok':
                $content = (new Icon('heart', ['class' => $class]))->setStyle('fa-regular');
                break;
            case 'crit':
                $content = new Icon('circle-exclamation', ['class' => $class]);
                break;
            case 'warning':
                $content = new Icon('exclamation-triangle', ['class' => $class]);
                break;
            case 'err':
                $content = (new Icon('circle-xmark', ['class' => $class]))->setStyle('fa-regular');
                break;
            case 'debug':
                $content = new Icon('bug-slash');
                break;
            case 'info':
                $content = new Icon('info');
                break;
            case 'alert':
                $content = new Icon('bell');
                break;
            case 'emerg':
                $content = new Icon('tower-broadcast');
                break;
            case 'notice':
                $content = new Icon('envelope');
                break;
        }

        if ($content) {
            $visual->addHtml($content);
        }
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        if ($this->item->incident->id !== null) {
            $title->addHtml(Html::tag('span', [], sprintf('#%d:', $this->item->incident->id)));
        }

        if (! $this->list->getNoSubjectLink()) {
            $content = new Link(
                $this->item->object->host,
                Links::event($this->item->id),
                ['class' => 'subject']
            );
        } else {
            $content = Html::tag('span', ['class' => 'subject'], $this->item->object->host);
        }

        if ($this->item->object->service) {
            $content = Html::sprintf(
                t('%s on %s', '<service> on <host>'),
                $content->setContent($this->item->object->service),
                Html::tag('span', ['class' => 'subject'], $this->item->object->host)
            );
        }

        $msg = null;
        if ($this->item->severity === null) {
            $msg = t('acknowledged');
        } elseif ($this->item->severity === 'ok') {
            $msg = t('recovered');
        } else {
            $msg = t('ran into a problem');
        }

        $title->addHtml($content);
        $title->addHtml(Html::tag('span', ['class' => 'state'], $msg));
    }

    protected function assembleCaption(BaseHtmlElement $caption)
    {
        $caption->add($this->item->message);
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
        $header->add(Html::tag(
            'span',
            ['class' => 'meta'],
            [$this->item->source->getIcon(), new TimeAgo($this->item->time->getTimestamp())]
        ));
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
        $main->add($this->createCaption());
        $main->add($this->createFooter());
    }
}
