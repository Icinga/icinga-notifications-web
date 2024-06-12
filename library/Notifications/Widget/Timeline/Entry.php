<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

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

        $dateType = \IntlDateFormatter::NONE;
        $timeType = \IntlDateFormatter::SHORT;
        if (
            $this->getStart()->diff($this->getEnd())->days > 0
            || $this->getStart()->format('Y-m-d') !== $this->getEnd()->format('Y-m-d')
        ) {
            $dateType = \IntlDateFormatter::SHORT;
        }

        $formatter = new \IntlDateFormatter(\Locale::getDefault(), $dateType, $timeType);

        $container->addAttributes([
            'title' => sprintf(
                $this->translate('%s is available from %s to %s'),
                $this->getMember()->getName(),
                $formatter->format($this->getStart()),
                $formatter->format($this->getEnd())
            )
        ]);
    }
}
