<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Control;

use DateTime;
use DateTimeZone;
use IntlTimeZone;
use ipl\Html\Form;
use ipl\I18n\Translation;
use ipl\Web\Common\FormUid;
use Throwable;

/**
 * A simple dropdown menu to pick a timezone.
 */
class TimezonePicker extends Form
{
    use Translation;
    use FormUid;

    /** @var string Default timezone param */
    public const DEFAULT_TIMEZONE_PARAM = 'display_timezone';

    protected $defaultAttributes = [
        'class' => 'timezone-picker icinga-form inline icinga-controls',
        'name' => 'timezone-picker-form'
    ];

    public function assemble(): void
    {
        $this->addElement($this->createUidElement());

        $validTz = [];
        foreach (IntlTimeZone::createEnumeration() as $tz) {
            try {
                if ((new DateTime('now', new DateTimeZone($tz)))->getTimezone()->getLocation()) {
                    $validTz[$tz] = $tz;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $this->addElement(
            'select',
            static::DEFAULT_TIMEZONE_PARAM,
            [
                'class'   => 'autosubmit',
                'label'   => $this->translate('Display Timezone'),
                'options' => $validTz
            ]
        );
    }
}
