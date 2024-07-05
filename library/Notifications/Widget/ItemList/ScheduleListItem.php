<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use DateTime;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\Timeline;
use Icinga\Module\Notifications\Widget\Timeline\Rotation;
use Icinga\Util\Csp;
use ipl\Html\BaseHtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Style;
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

    protected function assembleCaption(BaseHtmlElement $caption): void
    {
        // Number of days is set to 7, since default mode for schedule is week
        // and the start day should be the current day
        $timeline = (new Timeline((new DateTime())->setTime(0, 0), 7))
            ->minimalLayout()
            ->setStyle(
                (new Style())
                    ->setNonce(Csp::getStyleNonce())
                    ->setModule('notifications')
            );

        $rotations = $this->item
            ->rotation
            ->with('timeperiod')
            ->filter(Filter::equal('deleted', 'n'))
            ->orderBy('first_handoff', SORT_DESC);

        foreach ($rotations as $rotation) {
            $timeline->addRotation(new Rotation($rotation));
        }

        $caption->addHtml($timeline);
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->addHtml($this->createHeader(), $this->createCaption());
    }
}
