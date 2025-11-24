<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Form;

use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\Contract\FormElementDecorator;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Icon;

class EventRuleDecorator extends HtmlDocument implements FormElementDecorator
{
    /** @var FormElement */
    private FormElement $element;

    public function decorate(FormElement $formElement)
    {
        $me = clone $this;

        $me->element = $formElement;
        $formElement->prependWrapper($me);
    }

    protected function assemble()
    {
        $this->addHtml($this->element);

        if ($this->element->hasBeenValidated() && ! $this->element->isValid()) {
            $errors = new HtmlElement('ul', Attributes::create(['class' => 'errors']));
            foreach ($this->element->getMessages() as $message) {
                $errors->addHtml(new HtmlElement(
                    'li',
                    null,
                    new Icon('circle-exclamation', [
                        'title' => $message
                    ])
                ));
            }

            $this->addHtml($errors);
        }
    }
}
