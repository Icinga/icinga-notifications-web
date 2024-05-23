<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateTime;
use DateTimeInterface;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

/**
 * An entry on a time grid
 *
 * @phpstan-type GridContinuationType self::FROM_PREV_GRID | self::TO_NEXT_GRID | self::ACROSS_GRID
 * @phpstan-type EdgeContinuationType self::ACROSS_LEFT_EDGE | self::ACROSS_RIGHT_EDGE | self::ACROSS_BOTH_EDGES
 * @phpstan-type ContinuationType GridContinuationType | EdgeContinuationType
 */
class Entry extends BaseHtmlElement
{
    use Translation;

    /** @var string Continuation of an entry that started on the previous grid */
    public const FROM_PREV_GRID = 'from-prev-grid';

    /** @var string Continuation of an entry that continues on the next grid */
    public const TO_NEXT_GRID = 'to-next-grid';

    /** @var string Continuation of an entry that started on the previous grid and continues on the next */
    public const ACROSS_GRID = 'across-grid';

    /** @var string Continuation of an entry that started on a previous grid row */
    public const ACROSS_LEFT_EDGE = 'across-left-edge';

    /** @var string Continuation of an entry that continues on the next grid row */
    public const ACROSS_RIGHT_EDGE = 'across-right-edge';

    /** @var string Continuation of an entry that started on a previous grid row and continues on the next */
    public const ACROSS_BOTH_EDGES = 'across-both-edges';

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'entry'];

    protected $id;

    protected $description;

    protected $start;

    protected $end;

    /** @var ?int The 0-based position of the row where to place this entry on the grid */
    protected $position;

    /** @var ?ContinuationType */
    protected $continuationType;

    protected $rrule;

    /** @var Url */
    protected $url;

    protected $isOccurrence = false;

    /** @var Attendee */
    protected $attendee;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setStart(DateTime $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getStart(): ?DateTime
    {
        return $this->start;
    }

    public function setEnd(DateTime $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function getEnd(): ?DateTime
    {
        return $this->end;
    }

    /**
     * Set the position of the row where to place this entry on the grid
     *
     * @param ?int $position The 0-based position of the row
     *
     * @return $this
     */
    public function setPosition(?int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * Get the position of the row where to place this entry on the grid
     *
     * @return ?int The 0-based position of the row
     */
    public function getPosition(): ?int
    {
        return $this->position;
    }

    /**
     * Set the continuation type of this entry
     *
     * @param ?ContinuationType $continuationType
     *
     * @return $this
     */
    public function setContinuationType(?string $continuationType): self
    {
        $this->continuationType = $continuationType;

        return $this;
    }

    /**
     * Get the continuation type of this entry
     *
     * @return ?ContinuationType
     */
    public function getContinuationType(): ?string
    {
        return $this->continuationType;
    }

    public function setRecurrencyRule(?string $rrule): self
    {
        $this->rrule = $rrule;

        return $this;
    }

    public function getRecurrencyRule(): ?string
    {
        return $this->rrule;
    }

    public function setIsOccurrence(bool $state = true): self
    {
        $this->isOccurrence = $state;

        return $this;
    }

    public function isOccurrence(): bool
    {
        return $this->isOccurrence;
    }

    public function setUrl(?Url $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function getUrl(): ?Url
    {
        return $this->url;
    }

    public function setAttendee(Attendee $attendee): self
    {
        $this->attendee = $attendee;

        return $this;
    }

    public function getAttendee(): Attendee
    {
        return $this->attendee;
    }

    protected function assemble()
    {
        $this->getAttributes()
            ->add('data-entry-id', $this->getId())
            ->add('data-entry-position', $this->getPosition());

        $continuationType = $this->getContinuationType();
        if ($continuationType === self::ACROSS_GRID || $continuationType === self::ACROSS_BOTH_EDGES) {
            $this->getAttributes()->add('class', 'two-way-gradient');
        } elseif ($continuationType === self::FROM_PREV_GRID || $continuationType === self::ACROSS_LEFT_EDGE) {
            $this->getAttributes()->add('class', 'opening-gradient');
        } elseif ($continuationType === self::TO_NEXT_GRID || $continuationType === self::ACROSS_RIGHT_EDGE) {
            $this->getAttributes()->add('class', 'ending-gradient');
        }

        if (($url = $this->getUrl()) !== null) {
            $entryContainer = new Link(null, $url);
            $entryContainer->openInModal();
            $this->addHtml($entryContainer);
        } else {
            $entryContainer = $this;
        }

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

        $entryContainer->addHtml($content);
    }
}
