<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Control;

use DateTimeZone;
use ipl\Html\Form;
use ipl\Html\HtmlElement;
use ipl\I18n\Translation;

/**
 * A simple dropdown menu to pick a timezone.
 */
class TimezonePicker extends Form
{
    use Translation;

    /** @var string Default timezone param  */
    public const DEFAULT_TIMEZONE_PARAM = 'display_timezone';

    protected $defaultAttributes = ['class' => 'timezone-picker'];

    public function assemble(): void
    {
        $this->addElement(
            'select',
            static::DEFAULT_TIMEZONE_PARAM,
            [
                'class'   => 'autosubmit',
                'label'   => $this->translate('Display Timezone'),
                'options' => array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())
            ]
        );
        $select = $this->getElement(static::DEFAULT_TIMEZONE_PARAM);
        $select->prependWrapper(HtmlElement::create('div', ['class' => 'icinga-controls']));
    }
}
