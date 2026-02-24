<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Widget\Icon;

/**
 * A warning box that indicates the schedule timezone. It should be used to warn
 * the user that the display timezone differs from the schedule timezone.
 */
class TimezoneWarning extends BaseHtmlElement
{
    use Translation;

    protected $tag = 'div';

    protected $defaultAttributes = ['class' => 'timezone-warning'];

    /** @var string The schedule timezone */
    protected string $timezone;

    /**
     * @param string $timezone The schedule timezone
     */
    public function __construct(string $timezone)
    {
        $this->timezone = $timezone;
    }

    public function assemble(): void
    {
        $this->addHtml(new Icon('warning'));
        $this->addHtml(new HtmlElement(
            'p',
            null,
            new FormattedString(
                $this->translate(
                    'The schedule uses the %s timezone. All options you select below are based on this timezone.'
                ),
                [new HtmlElement('strong', null, new Text($this->timezone))]
            ),
        ));
    }
}
