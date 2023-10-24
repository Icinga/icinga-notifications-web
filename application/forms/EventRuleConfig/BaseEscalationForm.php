<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfig;

use Icinga\Module\Notifications\Widget\EventRuleConfig\FlowLine;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\Form;
use ipl\Html\HtmlElement;
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

    protected $defaultAttributes = ['class' => ['escalation-form', 'icinga-controls']];

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
                'class'             => ['add-button', 'spinner'],
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
        $this->add($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $this->add($this->createUidElement());

        $addButton = $this->createAddButton();

        $button = $this->getPressedSubmitElement();
        if ($button && $button->getName() === 'add') {
            $this->isAddPressed = true;
        }

        if ($this->count || $this->isAddPressed) {
            $this->assembleElements();
        }

        if ($this->options) {
            $wrapper = new HtmlElement('div', Attributes::create(['class' => 'option-wrapper']));
            $wrapper->addHtml(new HtmlElement('ul', Attributes::create(['class' => 'options']), ...$this->options));
            $wrapper->addHtml($addButton);
            $this->addHtml($wrapper);
        } else {
            $this->addHtml((new FlowLine())->getRightArrow());
            $this->addHtml($addButton);
        }
    }

    public function isAddButtonPressed(): ?bool
    {
        return $this->isAddPressed;
    }
}
