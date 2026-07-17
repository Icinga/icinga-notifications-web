<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class DeleteSourceForm extends CompatForm
{
    use CsrfCounterMeasure;

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

        $this->addElement('submit', 'delete', [
            'label' => $this->translate('Understood. Delete this source.'),
            'class' => 'btn-remove'
        ]);
    }
}
