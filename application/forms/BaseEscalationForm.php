<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Web\Session;
use ipl\Html\Contract\FormElement;
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

    /** @var ValidHtml[] */
    protected $options;

    /** @var ?int The counter of removed option */
    protected $removedOptionNumber;

    public function __construct(int $count)
    {
        $this->count = $count;
    }

    public function hasBeenSubmitted()
    {
        return false;
    }

    abstract protected function assembleElements(): void;

    protected function createAddButton(): FormElement
    {
        $addButton = $this->createElement(
            'submitButton',
            'add',
            [
                'class'             => ['add-button', 'control-button', 'spinner'],
                'label'             => new Icon('plus'),
                'title'             => $this->translate('Add more'),
                'formnovalidate'    => true
            ]
        );

        $this->registerElement($addButton);

        return $addButton;
    }

    protected function assemble()
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        $addButton = $this->createAddButton();

        $button = $this->getPressedSubmitElement();
        if ($button && $button->getName() === 'add') {
            $this->isAddPressed = true;
        }

        if ($this->count || $this->isAddPressed) {
            $this->assembleElements();
        }

        $this->add($addButton);
    }

    public function isAddButtonPressed(): ?bool
    {
        return $this->isAddPressed;
    }
}
