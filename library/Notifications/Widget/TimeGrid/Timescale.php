<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use DateTime;
use IntlDateFormatter;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Style;
use Locale;

/**
 * Creates a localized timescale for the TimeGrid
 */
class Timescale extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'timescale'];

    /** @var int The number of days shown */
    protected $days;

    /** @var Style */
    protected $style;

    /**
     * Create a new Timescale
     *
     * @param int $days
     * @param Style $style
     */
    public function __construct(int $days, Style $style)
    {
        $this->days = $days;
        $this->style = $style;
    }

    public function assemble(): void
    {
        if ($this->days === 1) {
            $timestampPerDay = 12;
        } elseif ($this->days <= 7) {
            $timestampPerDay = 2;
        } else {
            $timestampPerDay = 1;
        }

        $this->style->addFor($this, ['--timestampsPerDay' => $timestampPerDay * 2]); // *2 for .ticks

        $dateFormatter = new IntlDateFormatter(
            Locale::getDefault(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::SHORT
        );

        $timeIntervals = 24 / $timestampPerDay;

        $time = new DateTime();
        $dayTimestamps = [];
        for ($i = 0; $i < $timestampPerDay; $i++) {
            $stamp = array_map(
                function ($part) {
                    return new HtmlElement('span', null, new Text($part));
                },
                // am-pm is separated by non-breaking whitespace
                preg_split('/\s/u', $dateFormatter->format($time->setTime($i * $timeIntervals, 0)))
            );

            $dayTimestamps[] = new HtmlElement('span', new Attributes(['class' => 'timestamp']), ...$stamp);
            $dayTimestamps[] = new HtmlElement('span', new Attributes(['class' => 'ticks']));
        }

        $allTimestamps = array_merge(...array_fill(0, $this->days, $dayTimestamps));
        // clone is required because $allTimestamps contains references of same object
        $allTimestamps[] = (clone $allTimestamps[0])->addAttributes(['class' => 'midnight']); // extra stamp of 12AM

        $this->addHtml(...$allTimestamps);
    }
}
