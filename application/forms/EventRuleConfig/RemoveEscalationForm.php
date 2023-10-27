<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfig;

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
        'class' => ['remove-escalation-form', 'icinga-controls'],
    ];

    /** @var string  */
    private $disableReason;

    protected function assemble()
    {
        $this->add($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        $this->addElement(
            'submitButton',
            'remove',
            [
                'class' => ['remove-button', 'spinner'],
                'label' => new Icon('minus')
            ]
        );

        $this->getElement('remove')
            ->getAttributes()
            ->registerAttributeCallback('disabled', function () {
                return $this->disableReason !== null;
            })
            ->registerAttributeCallback('title', function () {
                return $this->disableReason ?? $this->translate('Remove escalation');
            });
    }

    /**
     * Disable the button and show the given reason in the title
     *
     * @param string $reason
     *
     * @return $this
     */
    public function setRemoveButtonDisabled(string $reason)
    {
        $this->disableReason = $reason;

        return $this;
    }
}
