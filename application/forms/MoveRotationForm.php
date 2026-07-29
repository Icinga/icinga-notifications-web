<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Web\Common\CsrfCounterMeasure;

class MoveRotationForm extends Form
{
    use CsrfCounterMeasure;

    protected $defaultAttributes = ['hidden' => true];

    public function getMessages(): array
    {
        $messages = parent::getMessages();
        foreach ($this->getElements() as $element) {
            foreach ($element->getMessages() as $message) {
                $messages[] = sprintf('%s: %s', $element->getName(), $message);
            }
        }

        return $messages;
    }

    protected function assemble(): void
    {
        $this->addElement('hidden', 'rotation', ['required' => true]);
        $this->addElement('hidden', 'priority', ['required' => true]);
        $this->addCsrfCounterMeasure(Session::getSession()->getId());
    }

    protected function onError(): void
    {
        $this->removeAttribute('hidden');

        parent::onError();
    }
}
