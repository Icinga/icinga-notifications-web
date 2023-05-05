<?php

namespace Icinga\Module\Noma\Widget\Calendar;

use DateTime;
use Icinga\Module\Noma\Widget\Calendar;
use ipl\Html\Form;
use ipl\Web\Common\BaseTarget;

class Controls extends Form
{
    use BaseTarget;

    protected $method = 'GET';

    protected function assemble()
    {
        $this->addElement('select', 'mode', [
            'class'     => 'autosubmit',
            'value'     => Calendar::MODE_WEEK,
            'options'   => [
                Calendar::MODE_WEEK => t('Calendar Week'),
                Calendar::MODE_MONTH => t('Month')
            ]
        ]);
        switch ($this->getPopulatedValue('mode', Calendar::MODE_WEEK)) {
            case Calendar::MODE_MONTH:
                $this->addElement('input', 'month', [
                    'class'     => 'autosubmit',
                    'type'      => 'month',
                    'value'     => (new DateTime())->format('Y-m')
                ]);
                break;
            case Calendar::MODE_WEEK:
            default:
                $this->addElement('input', 'week', [
                    'class'     => 'autosubmit',
                    'type'      => 'week',
                    'value'     => (new DateTime())->format('Y-\WW')
                ]);
                break;
        }
    }
}
