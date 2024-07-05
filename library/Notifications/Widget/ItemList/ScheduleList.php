<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use DateTime;
use Icinga\Module\Notifications\Widget\TimeGrid\DaysHeader;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Web\Common\BaseItemList;

class ScheduleList extends BaseItemList
{
    protected $defaultAttributes = ['class' => ['action-list', 'schedule-list']];

    protected function getItemClass(): string
    {
        return ScheduleListItem::class;
    }

    protected function assemble(): void
    {
        parent::assemble();

        $this->prependWrapper(
            (new HtmlDocument())->add(
                HtmlElement::create(
                    'div',
                    Attributes::create(['class' => 'schedules-header']),
                    new DaysHeader((new DateTime())->setTime(0, 0), 7)
                )
            )
        );
    }
}
