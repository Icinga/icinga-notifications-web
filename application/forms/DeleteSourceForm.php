<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use ipl\Html\Contract\Form;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\CalloutType;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Widget\Callout;

class DeleteSourceForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var bool Whether the source is locked */
    protected bool $locked = false;

    /**
     * Set whether the source is locked
     *
     * @param bool $locked
     *
     * @return $this
     */
    public function setLocked(bool $locked = true): static
    {
        $this->locked = $locked;

        return $this;
    }

    protected function assemble(): void
    {
        $this->applyDefaultElementDecorators();
        $this->addCsrfCounterMeasure();

        $this->addHtml(new HtmlElement(
            'p',
            null,
            Text::create($this->translate('Are you sure you want to delete this source?'))
        ));

        $this->addHtml(new HtmlElement(
            'ul',
            null,
            new HtmlElement(
                'li',
                null,
                Text::create($this->translate(
                    'Deleting a source also removes all related event rules and stops event processing for it.'
                ))
            ),
            new HtmlElement(
                'li',
                null,
                Text::create($this->translate(
                    'No new incidents will be opened or closed, and no further notifications will be sent.'
                ))
            )
        ));

        if ($this->locked) {
            $this->addHtml(new Callout(
                CalloutType::Warning,
                $this->translate(
                    'This source is managed by an integration and may cause malfunction if deleted without'
                    . ' disabling the integration first.'
                )
            ));
        }

        $this->addElement('submit', 'delete', [
            'label' => $this->translate('Understood. Delete this source.'),
            'class' => 'btn-remove'
        ]);
    }

    protected function onError()
    {
        // TODO: I feel like this should be the case in ipl-html already
        $this->emit(Form::ON_SENT, [$this]);
    }
}
