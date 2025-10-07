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
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\BaseTarget;
use ipl\Web\Style;
use ipl\Web\Widget\Icon;

class ScheduleDetail extends BaseHtmlElement
{
    use BaseTarget;
    use Translation;

    protected $tag = 'div';

    protected $defaultAttributes = ['id' => 'notifications-schedule', 'class' => 'schedule-detail'];

    /** @var Schedule */
    protected $schedule;

    /** @var Controls */
    protected $controls;

    /** @var bool */
    private bool $hasRotation = false;

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
            $this->hasRotation = true;
        }
    }

    /**
     * Create the timeline
     *
     * @return Timeline
     */
    protected function createTimeline(): Timeline
    {
        $timeline = new Timeline(
            $this->schedule->id,
            $this->controls->getStartDate(),
            $this->controls->getNumberOfDays()
        );
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
            new HtmlElement('div', Attributes::create(['class' => 'schedule-header']), $this->controls)
        );

        $timeline = $this->createTimeline();
        if (! $this->hasRotation) {
            $this->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'from-scratch-hint']),
                new Icon('info-circle'),
                new HtmlElement(
                    'div',
                    null,
                    Text::create($this->translate(
                        'With schedules contacts can rotate in recurring shifts. You can add'
                        . ' multiple rotation layers to a schedule.'
                    ))
                )
            ));
        }

        $this->addHtml(
            new HtmlElement('div', Attributes::create(['class' => 'schedule-container']), $timeline)
        );
    }
}
