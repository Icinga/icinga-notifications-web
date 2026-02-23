<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Timeline;

use DateInterval;
use DateTime;
use Icinga\Module\Notifications\Widget\TimeGrid\BaseGrid;
use Icinga\Module\Notifications\Widget\TimeGrid\GridStep;
use InvalidArgumentException;
use Traversable;

/**
 * A minimal grid with only a single row
 */
class MinimalGrid extends BaseGrid
{
    /** @var int Number of days to show in the grid */
    private const DAYS = 7;

    public function setGridStart(DateTime $start): BaseGrid
    {
        if ($start->format('H:i:s') !== '00:00:00') {
            throw new InvalidArgumentException('Start is not midnight');
        }

        return parent::setGridStart($start);
    }

    protected function calculateGridEnd(): DateTime
    {
        return (clone $this->getGridStart())->add(new DateInterval(sprintf('P%dD', self::DAYS)));
    }

    protected function getNoOfVisuallyConnectedHours(): int
    {
        return self::DAYS * 24;
    }

    protected function getMaximumRowSpan(): int
    {
        return 1;
    }

    protected function createGridSteps(): Traversable
    {
        $interval = new DateInterval('P1D');
        $dayStartsAt = clone $this->getGridStart();

        for ($x = 0; $x < self::DAYS; $x++) {
            $nextDay = (clone $dayStartsAt)->add($interval);

            yield new GridStep($dayStartsAt, $nextDay, $x, 0);

            $dayStartsAt = $nextDay;
        }
    }

    protected function assemble(): void
    {
        $this->style->addFor($this, [
            '--primaryRows'     => 1,
            '--primaryColumns'  => self::DAYS,
            '--columnsPerStep'  => 48,
            '--rowsPerStep'     => 1,
            '--stepRowHeight'   => '1.5em'
        ]);

        $overlay = $this->createGridOverlay();
        $this->addHtml(
            $this->createGrid(),
            $overlay
        );
    }
}
