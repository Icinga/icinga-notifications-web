<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Widget\InitialSetup;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

class IndexController extends CompatController
{
    public function indexAction(): void
    {
        $this->addTitleTab($this->translate('Initial Setup'));

        $setup = new InitialSetup();

        $this->addContent($setup);

        $setup->ensureAssembled();

        if ($setup->integrationAdded()) {
            $this->redirectNow(Url::fromPath('notifications'));
        } elseif ($setup->isFinished()) {
            $this->switchToSingleColumnLayout();
            $this->redirectNow(Url::fromPath('navigation/dashboard', ['name' => 'notifications']));
        }
    }
}
