<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Forms;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;
use ipl\Web\FormDecorator\IcingaFormDecorator;

class EventRuleForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    protected $defaultAttributes = [
        'class' => ['event-rule-form', 'icinga-form', 'icinga-controls'],
        'name'  => 'event-rule-form'
    ];

    protected function assemble()
    {
        $this->setDefaultElementDecorator(new IcingaFormDecorator());

        $this->add($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Title'),
                'class'     => 'autosubmit',
                'required'  => true
            ]
        );

        $this->addElement(
            'checkbox',
            'is_active',
            [
                'label'  => $this->translate('Event Rule is active'),
                'class'  => 'autosubmit',
                'value'  => 'y'
            ]
        );
    }
}
