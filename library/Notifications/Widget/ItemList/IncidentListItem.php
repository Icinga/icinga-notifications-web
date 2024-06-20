<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Widget\SourceIcon;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\I18n\Translation;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;
use ipl\Web\Widget\TimeSince;

/**
 * Incident item of an incident list. Represents one database row.
 */
class IncidentListItem extends BaseListItem
{
    use Translation;

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

        $this->getAttributes()
            ->set('data-icinga-detail-filter', Links::incident($this->item->id)->getQueryString());
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

        if (! $this->list->getNoSubjectLink()) {
            $content = new Link(null, Links::incident($this->item->id));
        } else {
            $content = new HtmlElement('span');
        }

        /** @var Objects $obj */
        $obj = $this->item->object;
        $name = $obj->getName();

        $content->addAttributes($name->getAttributes());
        $content->addFrom($name);

        $title->addHtml($content);
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
        $meta = new HtmlElement('span', Attributes::create(['class' => 'meta']));

        /** @var Source $source */
        $source = $this->item->object->source;
        $meta->addHtml((new SourceIcon(SourceIcon::SIZE_BIG))->addHtml($source->getIcon()));

        if ($this->item->recovered_at !== null) {
            $meta->addHtml(FormattedString::create(
                $this->translate('closed %s', '(incident) ... <relative time>'),
                new TimeAgo($this->item->recovered_at->getTimestamp())
            ));
        } else {
            $meta->addHtml(new TimeSince($this->item->started_at->getTimestamp()));
        }

        $header->addHtml($meta);
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
