<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Widget\Icon;

/**
 * A warning box that indicates the schedule's timezone. It should be used to warn
 * the user that the display timezone differs from the schedule's timezone.
 */
class TimezoneWarning extends BaseHtmlElement
{
    use Translation;

    public function __construct(
        protected string $timezone,
        protected $tag = 'div',
        protected $defaultAttributes = ['class' => 'timezone-warning']
    ) {
    }

    public function assemble(): void
    {
        $this->addHtml(new Icon('warning'));
        $this->addHtml(new HtmlElement(
            'p',
            null,
            new Text($this->translate('The schedule\'s timezone is ')),
            new HtmlElement('strong', null, new Text($this->timezone))
        ));
    }
}
