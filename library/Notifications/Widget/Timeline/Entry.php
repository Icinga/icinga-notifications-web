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
    protected $flyoutContent;

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

    /** Sets Content of a Flyout that is shown when the Entry is hovered
     * @param ValidHtml $content
     * @return $this
     */
    public function setFlyoutContent(ValidHtml $content): self
    {
        $this->flyoutContent = $content;
        return $this;
    }

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
