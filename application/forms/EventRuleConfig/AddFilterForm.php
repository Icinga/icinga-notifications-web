<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfig;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;
use ipl\Web\Widget\Icon;

class AddFilterForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    protected $defaultAttributes = [
        'class' => ['add-filter-form', 'icinga-controls'],
        'name'  => 'add-filter-form'
    ];


    protected function assemble()
    {
        $this->add($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());


        $this->addElement(
            'submitButton',
            'add',
            [
                'class' => ['add-button', 'spinner'],
                'label' => new Icon('plus'),
                'title' => $this->translate('Add filter')
            ]
        );
    }
}
