<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;
use ipl\Web\Widget\Icon;

class AddEscalationForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    protected $defaultAttributes = [
        'class' => ['add-escalation-form', 'icinga-form', 'icinga-controls'],
        'name'  => 'add-escalation-form'
    ];


    protected function assemble()
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->addElement($this->createUidElement());


        $this->addElement(
            'submitButton',
            'add',
            [
                'class' => ['add-button', 'control-button', 'spinner'],
                'label' => new Icon('plus'),
                'title' => $this->translate('Add a new escalation')
            ]
        );
    }
}
