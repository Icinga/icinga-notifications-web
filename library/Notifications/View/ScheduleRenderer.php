<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\View;

use DateTime;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\Timeline;
use Icinga\Module\Notifications\Widget\Timeline\Rotation;
use Icinga\Util\Csp;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Style;
use ipl\Web\Widget\Link;

/** @implements ItemRenderer<Schedule> */
class ScheduleRenderer implements ItemRenderer
{
    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('schedule');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $title->addHtml(
            new Link(
                $item->name,
                Links::schedule($item->id),
                ['class' => 'subject']
            )
        );
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
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

        $rotations = $item->rotation->with('timeperiod')->orderBy('first_handoff', SORT_DESC);

        foreach ($rotations as $rotation) {
            $timeline->addRotation(new Rotation($rotation));
        }

        $caption->addHtml($timeline);
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }
}
