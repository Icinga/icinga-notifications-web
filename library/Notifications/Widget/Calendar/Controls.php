<?php

namespace Icinga\Module\Notifications\Widget\Calendar;

use DateTime;
use Icinga\Module\Notifications\Widget\Calendar;
use ipl\Html\Attributes;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\BaseTarget;
use ipl\Web\Compat\CompatForm;

class Controls extends CompatForm
{
    use BaseTarget;

    protected $method = 'GET';

    public function getViewMode(): string
    {
        return $this->getPopulatedValue('mode', Calendar::MODE_WEEK);
    }

    protected function assemble()
    {
        $this->addAttributes(['class' => ['calendar-controls', 'inline']]);

        switch ($this->getPopulatedValue('mode', Calendar::MODE_WEEK)) {
            case Calendar::MODE_MONTH:
                $this->addElement('input', 'month', [
                    'class'     => 'autosubmit',
                    'type'      => 'month',
                    'value'     => (new DateTime())->format('Y-m'),
                    'label'     => $this->translate('Month')
                ]);
                break;
            case Calendar::MODE_WEEK:
            default:
                $this->addElement('input', 'week', [
                    'class'     => 'autosubmit',
                    'type'      => 'week',
                    'value'     => (new DateTime())->format('Y-\WW'),
                    'label'     => $this->translate('Calendar Week')
                ]);
                break;
        }

        $modeParam = 'mode';
        $options = [
            Calendar::MODE_WEEK => t('Week'),
            Calendar::MODE_MONTH => t('Month')
        ];

        $modeSwitcher = HtmlElement::create('fieldset', ['class' => 'view-mode-switcher']);
        foreach ($options as $value => $label) {
            $input = $this->createElement('input', $modeParam, [
                'class' => 'autosubmit',
                'type'  => 'radio',
                'id' => $modeParam . '-' . $value,
                'value' => $value
            ]);

            $input->getAttributes()->registerAttributeCallback('checked', function () use ($value) {
                return $value === $this->getViewMode();
            });

            $modeSwitcher->addHtml(
                $input,
                new HtmlElement('label', Attributes::create(['for' => 'mode-' . $value]), Text::create($label))
            );
        }

        $this->addHtml($modeSwitcher);
    }
}
