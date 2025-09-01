<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class EventRuleForm extends CompatForm
{
    use CsrfCounterMeasure;
    use Translation;

    protected function assemble(): void
    {
        $this->addCsrfCounterMeasure();

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Title'),
                'required'  => true
            ]
        );

        $this->addElement('submit', 'btn_submit', [
            'label' => $this->translate('Save')
        ]);
    }
}
