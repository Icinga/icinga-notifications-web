<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use Icinga\Module\Notifications\Widget\TimeGrid;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\Web\Widget\Icon;

class Entry extends TimeGrid\Entry
{
    /** @var Member */
    protected $member;

    /** @var ?ValidHtml Content of the flyoutmenu that is shown when the entry is hovered */
    protected ?ValidHtml $flyoutContent;

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
     * @param ValidHtml $content
     *
     * @return static
     */
    public function setFlyoutContent(ValidHtml $content): static
    {
        $this->flyoutContent = $content;

        return $this;
    }

    /**
     * Return the content of the entries tooltip
     *
     * @return ValidHtml|null
     */
    public function getFlyoutContent(): ?ValidHtml
    {
        return $this->flyoutContent;
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

        $this->addHtml($this->flyoutContent);
    }
}
