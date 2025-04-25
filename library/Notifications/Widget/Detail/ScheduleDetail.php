<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Model\Schedule;
use Icinga\Module\Notifications\Widget\Detail\ScheduleDetail\Controls;
use Icinga\Module\Notifications\Widget\Timeline;
use Icinga\Module\Notifications\Widget\Timeline\Rotation;
use Icinga\Util\Csp;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Web\Common\BaseTarget;
use ipl\Web\Style;

class ScheduleDetail extends BaseHtmlElement
{
    use BaseTarget;

    protected $tag = 'div';

    protected $defaultAttributes = ['id' => 'notifications-schedule', 'class' => 'schedule-detail'];

    /** @var Schedule */
    protected $schedule;

    /** @var Controls */
    protected $controls;

    /**
     * Create a new Schedule
     *
     * @param Schedule $schedule
     * @param Controls $controls
     */
    public function __construct(Schedule $schedule, Controls $controls)
    {
        $this->schedule = $schedule;
        $this->controls = $controls;
    }

    /**
     * Assemble the timeline
     *
     * @param Timeline $timeline
     */
    protected function assembleTimeline(Timeline $timeline): void
    {
        foreach ($this->schedule->rotation->with('timeperiod')->orderBy('first_handoff', SORT_DESC) as $rotation) {
            $timeline->addRotation(new Rotation($rotation));
        }
    }

    /**
     * Create the timeline
     *
     * @return Timeline
     */
    protected function createTimeline(): Timeline
    {
        $timeline = new Timeline($this->controls->getStartDate(), $this->controls->getNumberOfDays());
        $timeline->setStyle(
            (new Style())
                ->setNonce(Csp::getStyleNonce())
                ->setModule('notifications')
        );

        $this->assembleTimeline($timeline);

        return $timeline;
    }

    protected function assemble()
    {
        $this->addHtml(
            new HtmlElement('div', Attributes::create(['class' => 'schedule-header']), $this->controls),
            new HtmlElement('div', Attributes::create(['class' => 'schedule-container']), $this->createTimeline())
        );
    }
}
