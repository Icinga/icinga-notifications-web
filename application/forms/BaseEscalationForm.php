<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Forms;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Common\FormUid;
use ipl\Web\Widget\Icon;

abstract class BaseEscalationForm extends Form
{
    use CsrfCounterMeasure;
    use FormUid;
    use Translation;

    protected $defaultAttributes = ['class' => ['escalation-form', 'icinga-form', 'icinga-controls']];

    /** @var int The count of existing conditions/recipients */
    protected $count;

    /** @var bool Whether the `add` button is pressed */
    protected $isAddPressed;

    /** @var ValidHtml The last added wrapper */
    protected $lastContent;

    public function __construct(int $count)
    {
        $this->count = $count;
    }

    public function hasBeenSubmitted()
    {
        return false;
    }

    abstract protected function assembleElements(): void;

    protected function assembleAddAndRemoveButton()
    {
        $addButton = $this->createElement(
            'submitButton',
            'add',
            [
                //TODO: need changes
                'class'             => ['add-button', 'control-button', 'spinner'],
                'label'             => new Icon('plus'),
                'title'             => $this->translate('Add more'),
                'formnovalidate'    => true
            ]
        );

        $this->registerElement($addButton);

        $removeButton = $this->createElement(
            'submitButton',
            'remove',
            [
                //TODO: need changes
                'class'             => ['remove-button', 'control-button', 'spinner'],
                'label'             => new Icon('minus'),
                'title'             => $this->translate('Remove'),
                'formnovalidate'    => true
            ]
        );

        $this->registerElement($removeButton);

        $button = $this->getPressedSubmitElement();
        if ($button !== null) {
            if ($button->getName() === 'add') {
                $this->isAddPressed = true;
                $this->assembleElements();
            } elseif ($button->getName() === 'remove') {
                $this->remove($this->lastContent);
                $this->count--;
            }
        }

        $this->add($addButton);

        if ($this->count > 1 || $this->isAddPressed || ($this instanceof EscalationConditionForm && $this->count > 0)) {
            $this->add($removeButton);
        }
    }

    protected function assemble()
    {
        $this->add($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        if ($this->count) {
            $this->assembleElements();
        }

        $this->assembleAddAndRemoveButton();
    }
}
