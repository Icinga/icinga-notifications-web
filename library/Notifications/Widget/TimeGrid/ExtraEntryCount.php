<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateTime;
use ipl\I18n\Translation;
use ipl\Web\Widget\ButtonLink;

class ExtraEntryCount extends ButtonLink
{
    use Translation;

    /** @var BaseGrid Grid this extra count is tied to*/
    protected $grid;

    /** @var DateTime Grid step for which the extra count is being registered */
    protected $gridStep;

    /**
     * Set the grid this extra count is tied to
     *
     * @param BaseGrid $grid
     *
     * @return $this
     */
    public function setGrid(BaseGrid $grid): self
    {
        $this->grid = $grid;

        return $this;
    }

    /**
     * Set the grid step for which the extra count is being registered
     *
     * @param DateTime $gridStep
     *
     * @return $this
     */
    public function setGridStep(DateTime $gridStep): self
    {
        $this->gridStep = clone $gridStep;

        return $this;
    }

    protected function assemble()
    {
        $count = $this->grid->getExtraEntryCount($this->gridStep);
        $this->addAttributes(['class' => 'extra-count'])
            ->setBaseTarget('_self')
            ->setContent(
                sprintf(
                    $this->translatePlural(
                        '+%d entry',
                        '+%d entries',
                        $count
                    ),
                    $count
                )
            );
    }

    public function renderUnwrapped()
    {
        if ($this->grid->getExtraEntryCount($this->gridStep) > 0) {
            return parent::renderUnwrapped();
        }

        return '';
    }
}
