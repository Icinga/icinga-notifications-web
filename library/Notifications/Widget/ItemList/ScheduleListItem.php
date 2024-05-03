<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Html\BaseHtmlElement;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Link;

/**
 * Schedule item of a schedule list. Represents one database row.
 */
class ScheduleListItem extends BaseListItem
{
    /** @var Schedule The associated list item */
    protected $item;

    /** @var ScheduleList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true);
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link(
            $this->item->name,
            Links::schedule($this->item->id),
            ['class' => 'subject']
        ));
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->addHtml($this->createTitle());
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->addHtml($this->createHeader());
    }
}
