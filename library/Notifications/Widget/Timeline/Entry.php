<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use Icinga\Module\Notifications\Widget\TimeGrid\BaseGrid;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use Icinga\Module\Notifications\Widget\TimeGrid;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Widget\Icon;

class Entry extends TimeGrid\Entry
{
    /** @var Member */
    protected $member;

    /** @var ?EntryFlyout Content of the flyoutmenu that is shown when the entry is hovered */
    protected ?EntryFlyout $flyoutContent = null;

    /**
     * @var string A CSS class that changes the placement of the flyout
     *
     * "narrow-entry": centers the flyout's caret on the entry
     * "medium-entry": behaves like narrow entry in minimal and poor layout, otherwise as a wide entry
     * "wide-entry": the flyout has a fixed offset
     */
    protected string $widthClass = "wide-entry";

    public function setMember(Member $member): self
    {
        $this->member = $member;

        return $this;
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function getColor(int $transparency): string
    {
        return TimeGrid\Util::calculateEntryColor($this->getMember()->getName(), $transparency);
    }

    /**
     * Set content of a tooltip that is shown when the entry is hovered
     *
     * @param EntryFlyout $content
     *
     * @return static
     */
    public function setFlyoutContent(EntryFlyout $content): static
    {
        $this->flyoutContent = $content;

        return $this;
    }

    /**
     * Return the entry's flyout element
     *
     * @return EntryFlyout|null
     */
    public function getFlyoutContent(): ?EntryFlyout
    {
        return $this->flyoutContent;
    }

    /**
     * Set value of $widthClass which will be a CSS class of the rendered entry
     *
     * @param string $widthClass
     *
     * @return $this
     */
    public function setWidthClass(string $widthClass): static
    {
        $this->widthClass = $widthClass;

        return $this;
    }

    /**
     * Return the current width class
     *
     * @return string
     */
    public function getWidthClass(): string
    {
        return $this->widthClass;
    }

    /**
     * Assign a width class based on the fraction of the grid duration occupied by this entry
     *
     * @param BaseGrid $grid
     * @param float $mediumThreshold Fraction of grid duration below which the entry is considered medium width
     * @param float $narrowThreshold Fraction of grid duration below which the entry is considered narrow
     *
     * @return $this
     */
    public function calculateAndSetWidthClass(BaseGrid $grid, $mediumThreshold = 0.2, $narrowThreshold = 0.1): static
    {
        $totalGridDuration = $grid->getGridEnd()->getTimestamp() - $grid->getGridStart()->getTimestamp();
        $start = max($this->getStart()->getTimestamp(), $grid->getGridStart()->getTimestamp());
        $end = min($this->getEnd()->getTimestamp(), $grid->getGridEnd()->getTimestamp());
        $duration = $end - $start;
        if ($duration / $totalGridDuration < $narrowThreshold) {
            $this->setWidthClass('narrow-entry');
        } elseif ($duration / $totalGridDuration < $mediumThreshold) {
            $this->setWidthClass('medium-entry');
        } else {
            $this->setWidthClass('wide-entry');
        }

        return $this;
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
        $container->addHtml(
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'title']),
                new Icon($this->getMember()->getIcon()),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'name']),
                    Text::create($this->getMember()->getName())
                )
            )
        );

        if (isset($this->flyoutContent)) {
            $this->addHtml($this->flyoutContent->withActiveMember($this->getMember()));
            $this->getAttributes()->add('class', $this->getWidthClass());
        }
    }
}
