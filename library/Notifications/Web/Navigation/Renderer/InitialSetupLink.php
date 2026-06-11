<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Web\Navigation\Renderer;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Web\Navigation\NavigationItem;
use Icinga\Web\Navigation\Renderer\NavigationItemRenderer;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlString;
use ipl\I18n\Translation;
use ipl\Sql\Expression;
use ipl\Web\Widget\Icon;

/**
 * Renders the initial setup link with an icon if the table `source` is empty.
 */
class InitialSetupLink extends NavigationItemRenderer
{
    use Translation;

    public function render(?NavigationItem $item = null): string
    {
        if ($item === null) {
            $item = $this->getItem();
        }

        $html = new HtmlDocument();
        if (Source::on(Database::get())->columns([new Expression('1')])->limit(1)->first() === null) {
            $this->setEscapeLabel(false);
            $item->setLabel(
                $this->view()->escape($item->getLabel())
                . new Icon('exclamation-triangle', [
                    'class' => 'initial-setup-icon',
                    'title' => $this->translate('Initial setup required')
                ])
            );

            $item->setUrl('notifications');
            $item->setCssClass('icinga-module module-notifications');
        }

        return $html
            ->prependHtml(new HtmlString(parent::render($item)))
            ->render();
    }
}
