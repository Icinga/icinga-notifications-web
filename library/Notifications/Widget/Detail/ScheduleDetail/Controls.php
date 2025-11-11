<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail\ScheduleDetail;

use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\FormUid;

class Controls extends Form
{
    use Translation;
    use FormUid;

    /** @var string The default mode */
    public const DEFAULT_MODE = 'week';

    protected $method = 'POST';

    protected $defaultAttributes = ['class' => 'schedule-controls', 'name' => 'schedule-detail-controls-form'];

    /**
     * Get the number of days the user wants to see
     *
     * @return int
     */
    public function getNumberOfDays(): int
    {
        return match ($this->getPopulatedValue('mode')) {
            'day' => 1,
            'weeks' => 14,
            'month' => 31,
            default => 7
        };
    }

    protected function assemble()
    {
        $this->addElement($this->createUidElement());

        $param = 'mode';
        $options = [
            'day' => $this->translate('Day'),
            'week' => $this->translate('Week'),
            'weeks' => $this->translate('2 Weeks'),
            'month' => $this->translate('Month')
        ];

        $this->addElement('hidden', $param, ['required' => true]);

        $chosenMode = $this->getPopulatedValue('mode');
        $viewModeSwitcher = HtmlElement::create('fieldset', ['class' => 'view-mode-switcher']);
        foreach ($options as $value => $label) {
            $input = $this->createElement('input', $param, [
                'class' => 'autosubmit',
                'type'  => 'radio',
                'id' => $param . '-' . $value,
                'value' => $value,
                'checked' => $value === $chosenMode
            ]);

            $viewModeSwitcher->addHtml(
                $input,
                new HtmlElement('label', Attributes::create(['for' => $param . '-' . $value]), Text::create($label))
            );
        }

        $this->addHtml($viewModeSwitcher);
    }
}
