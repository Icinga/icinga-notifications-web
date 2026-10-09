<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\RuleEntryRecipient;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Widget\Icon;

class RuleEntryRecipientBadge extends BaseHtmlElement
{
    protected RuleEntryRecipient $recipient;

    protected ?int $moreCount = null;

    protected $tag = 'span';

    protected $defaultAttributes = ['class' => 'rule-entry-recipient-badge'];

    /**
     * Create the rule entry recipient badge with icon
     *
     * @param RuleEntryRecipient $recipient
     * @param ?int $moreCount The more count to show
     */
    public function __construct(RuleEntryRecipient $recipient, ?int $moreCount = null)
    {
        $this->recipient = $recipient;
        $this->moreCount = $moreCount;
    }

    protected function assembleBadge(): void
    {
        $recipientModel = $this->recipient->getRecipient();
        if ($recipientModel === null) {
            return;
        }

        $nameColumn = 'name';
        $icon = 'users';

        if ($recipientModel instanceof Contact) {
            $nameColumn = 'full_name';
            $icon = 'user';
        }

        $this->addHtml(new HtmlElement(
            'span',
            null,
            new Icon($icon),
            Text::create($recipientModel->$nameColumn)
        ));
    }

    protected function assemble(): void
    {
        $this->assembleBadge();

        if ($this->moreCount) {
            $this->add(new HtmlElement(
                'span',
                null,
                Text::create(sprintf(' + %d more', $this->moreCount))
            ));
        }
    }
}
