<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateTime;
use ipl\Html\BaseHtmlElement;
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
abstract class Entry extends BaseHtmlElement
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

    /** @var int The entry id */
    protected int $id;

    /** @var ?DateTime When the entry starts */
    protected ?DateTime $start = null;

    /** @var ?DateTime When the entry ends */
    protected ?DateTime $end = null;

    /** @var ?int The 0-based position of the row where to place this entry on the grid */
    protected ?int $position = null;

    /** @var ?ContinuationType The continuation type */
    protected ?string $continuationType = null;

    /** @var ?Url The URL to show this entry */
    protected ?Url $url = null;

    /**
     * Create a new entry
     *
     * @param int $id The entry id
     */
    public function __construct(int $id)
    {
        $this->id = $id;
    }

    /**
     * Get the entry id
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Set the start date and time of the entry
     *
     * @param DateTime $start
     *
     * @return $this
     */
    public function setStart(DateTime $start): self
    {
        $this->start = $start;

        return $this;
    }

    /**
     * Get the start date and time of the entry
     *
     * @return ?DateTime
     */
    public function getStart(): ?DateTime
    {
        return $this->start;
    }

    /**
     * Set the end date and time of the entry
     *
     * @param DateTime $end
     *
     * @return $this
     */
    public function setEnd(DateTime $end): self
    {
        $this->end = $end;

        return $this;
    }

    /**
     * Get the end date and time of the entry
     *
     * @return ?DateTime
     */
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

    /**
     * Set the URL to show this entry
     *
     * @param ?Url $url
     *
     * @return $this
     */
    public function setUrl(?Url $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get the URL to show this entry
     *
     * @return ?Url
     */
    public function getUrl(): ?Url
    {
        return $this->url;
    }

    /**
     * Get entry's color with the given transparency suitable for CSS
     *
     * @param int<0, 100> $transparency
     *
     * @return string
     */
    abstract public function getColor(int $transparency): string;

    abstract protected function assembleContainer(BaseHtmlElement $container): void;

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

        $this->assembleContainer($entryContainer);
    }
}
