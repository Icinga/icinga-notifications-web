<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Control;

use DateTime;
use DateTimeZone;
use Icinga\Web\Session;
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

    protected string $defaultTimezone;

    public function __construct(string $defaultTimezone)
    {
        $this->defaultTimezone = $defaultTimezone;
    }

    /**
     * Get the chosen display timezone
     *
     * @return string
     */
    public function getDisplayTimezone(): string
    {
        return $this->getPopulatedValue(static::DEFAULT_TIMEZONE_PARAM)
            ?? Session::getSession()->getNamespace('notifications')
                ->get('schedule.display_timezone', $this->defaultTimezone);
    }

    /**
     * On success store the display timezone in the session
     *
     * @return void
     */
    protected function onSuccess(): void
    {
        Session::getSession()->getNamespace('notifications')
            ->set('schedule.display_timezone', $this->getValue(static::DEFAULT_TIMEZONE_PARAM));
    }

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
        $select = $this->getElement(static::DEFAULT_TIMEZONE_PARAM);
        $select->setValue($this->getDisplayTimezone());
    }
}
