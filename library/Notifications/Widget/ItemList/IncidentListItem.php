<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\BaseListItem;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Incident;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;
use ipl\Web\Widget\TimeSince;

/**
 * Event item of an event list. Represents one database row.
 */
class IncidentListItem extends BaseListItem
{
    /** @var Incident The associated list item */
    protected $item;

    /** @var IncidentList The list where the item is part of */
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
        $content = new Icon($this->getSeverityIcon(), ['class' => ['severity-' . $this->item->severity]]);

        if ($this->item->severity === 'ok' || $this->item->severity === 'err') {
            $content->setStyle('fa-regular');
        }

        $visual->addHtml($content);
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(Html::tag('span', [], sprintf('#%d:', $this->item->id)));

        if ($this->item->object->service) {
            $content = Html::sprintf(
                t('%s on %s', '<service> on <host>'),
                new Link(
                    $this->item->object->service,
                    Links::incident($this->item->id),
                    ['class' => 'subject']
                ),
                Html::tag('span', ['class' => 'subject'], $this->item->object->host)
            );
        } else {
            $content = new Link(
                $this->item->object->host,
                Links::incident($this->item->id),
                ['class' => 'subject']
            );
        }

        $title->addHtml($content);
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());

        if ($this->item->recovered_at !== null) {
            $header->add(Html::tag(
                'span',
                ['class' => 'meta'],
                [
                    'closed ',
                    new TimeAgo($this->item->recovered_at->getTimestamp())
                ]
            ));
        } else {
            $header->add(new TimeSince($this->item->started_at->getTimestamp()));
        }
    }

    protected function getSeverityIcon(): string
    {
        switch ($this->item->severity) {
            case 'ok':
                return Icons::OK;
            case 'err':
                return Icons::ERROR;
            case 'crit':
                return Icons::CRITICAL;
            default:
                return Icons::WARNING;
        }
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
    }
}
