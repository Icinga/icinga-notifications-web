<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Exception;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEntry;
use Icinga\Module\Notifications\Util\RuleSerializer;
use Icinga\Module\Notifications\Widget\RuleEntryRecipientBadge;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/** @implements ItemRenderer<Rule> */
class EventRuleRenderer implements ItemRenderer
{
    use Translation;

    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('rule');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $migrationRequired = false;
        if ($item->object_filter) {
            try {
                RuleSerializer::decode($item->object_filter);
            } catch (Exception) {
                $migrationRequired = true;
            }
        }

        if ($migrationRequired) {
            /** TODO: Drop this or re-factor this together with {@see EventRuleController::migrateAction()} */
            $title->addHtml(
                new Link(
                    $item->name,
                    Url::fromPath('notifications/event-rule/migrate', ['id' => $item->id]),
                    ['class' => 'subject']
                ),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'migration-alert']),
                    new Icon('triangle-exclamation'),
                    Text::create($this->translate(
                        'This rule is outdated. Click here to migrate it.'
                    )),
                    new Icon('triangle-exclamation')
                )
            );
        } else {
            $title->addHtml(new Link($item->name, Links::eventRule($item->id), ['class' => 'subject']));
            $title->addHtml(Text::create(match ($item->type) {
                'escalation' => $this->translate('Escalation Rule'),
                'notification' => $this->translate('Notification Rule')
            }));
        }
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        /** @var ?RuleEntry $rs */
        $rs = $item->rule_entry->first();
        if ($rs) {
            $recipientCount = $rs->rule_entry_recipient->count();
            if ($recipientCount) {
                $info->addHtml(new RuleEntryRecipientBadge(
                    $rs->rule_entry_recipient->first(),
                    $recipientCount - 1
                ));
            }
        }
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
        if ($item->object_filter) {
            $footer->addHtml(new Icon('filter'));
        }

        $entryCount = $item->rule_entry->count();
        if ($entryCount > 1) {
            $footer->addHtml(new Icon('code-branch'), new Text($entryCount));
        }
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }
}
