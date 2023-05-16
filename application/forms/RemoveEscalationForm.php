<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;
use ipl\Web\Widget\Icon;

class RemoveEscalationForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    protected $defaultAttributes = [
        'class' => ['remove-escalation-form', 'icinga-form', 'icinga-controls'],
    ];

    /** @var bool  */
    private $disableRemoveButtton;

    protected function assemble()
    {
        $this->add($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        $this->addElement(
            'submitButton',
            'remove',
            [
                'class' => ['remove-button', 'control-button', 'spinner'],
                'label' => new Icon('minus')
            ]
        );

        $this->getElement('remove')
            ->getAttributes()
            ->registerAttributeCallback('disabled', function () {
                return $this->disableRemoveButtton;
            })
            ->registerAttributeCallback('title', function () {
                if ($this->disableRemoveButtton) {
                    return $this->translate(
                        'There exist active incidents for this escalation and hence cannot be removed'
                    );
                }

                return $this->translate('Remove escalation');
            });
    }

    /**
     * Method to set disabled state of remove button
     *
     * @param bool $disable
     *
     * @return $this
     */
    public function setRemoveButtonDisabled(bool $state = false)
    {
        $this->disableRemoveButtton = $state;

        return $this;
    }
}
