<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateTimeInterface;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use Icinga\Module\Notifications\Widget\TimeGrid;

/**
 * An entry on a calendar
 */
class Entry extends TimeGrid\Entry
{
    /** @var ?string The description */
    protected $description;

    /** @var Attendee */
    protected $attendee;

    /**
     * Set the description
     *
     * @param ?string $description
     *
     * @return $this
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get the description
     *
     * @return ?string
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Set the attendee
     *
     * @param Attendee $attendee
     *
     * @return $this
     */
    public function setAttendee(Attendee $attendee): self
    {
        $this->attendee = $attendee;

        return $this;
    }

    /**
     * Get the attendee
     *
     * @return Attendee
     */
    public function getAttendee(): Attendee
    {
        return $this->attendee;
    }

    public function getColor(int $transparency): string
    {
        return TimeGrid\Util::calculateEntryColor($this->getAttendee()->getName(), $transparency);
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
        $title = new HtmlElement('div', Attributes::create(['class' => 'title']));
        $content = new HtmlElement(
            'div',
            Attributes::create(['class' => 'content'])
        );

        $description = $this->getDescription();
        $titleAttr = $this->getStart()->format('H:i')
            . ' | ' . $this->getAttendee()->getName()
            . ($description ? ': ' . $description : '');

        $startText = null;
        $endText = null;

        $continuationType = $this->getContinuationType();
        if ($continuationType === self::ACROSS_GRID) {
            $startText = sprintf($this->translate('starts %s'), $this->getStart()->format('d/m/y'));
            $endText = sprintf($this->translate('ends %s'), $this->getEnd()->format('d/m/y H:i'));
        } elseif ($continuationType === self::FROM_PREV_GRID) {
            $startText = sprintf($this->translate('starts %s'), $this->getStart()->format('d/m/y'));
        } elseif ($continuationType === self::TO_NEXT_GRID) {
            $endText = sprintf($this->translate('ends %s'), $this->getEnd()->format('d/m/y H:i'));
        }

        if ($startText) {
            $titleAttr = $startText . ' ' . $titleAttr;
        }

        if ($endText) {
            $titleAttr = $titleAttr . ' | ' . $endText;
        }

        $content->addAttributes(['title' => $titleAttr]);

        if ($continuationType !== null) {
            $title->addHtml(new HtmlElement(
                'time',
                Attributes::create([
                    'datetime' => $this->getStart()->format(DateTimeInterface::ATOM)
                ]),
                Text::create($this->getStart()->format($startText ? 'd/m/y H:i' : 'H:i'))
            ));
        }

        $title->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'attendee']),
                $this->getAttendee()->getIcon(),
                Text::create($this->getAttendee()->getName())
            )
        );

        $content->addHtml($title);
        if ($description) {
            $content->addHtml(new HtmlElement(
                'div',
                Attributes::create(['class' => 'description']),
                new HtmlElement(
                    'p',
                    Attributes::create(['title' => $description]),
                    Text::create($description)
                )
            ));
        }

        if ($endText) {
            $content->addHtml(
                HtmlElement::create(
                    'div',
                    ['class' => 'ends-at'],
                    $endText
                )
            );
        }

        $container->addHtml($content);
    }
}
