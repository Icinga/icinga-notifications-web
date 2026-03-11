<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Widget\RuleEscalationRecipientBadge;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\Text;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/** @implements ItemRenderer<Rule> */
class EventRuleRenderer implements ItemRenderer
{
    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('rule');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $title->addHtml(new Link($item->name, Links::eventRule($item->id), ['class' => 'subject']));
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        $rs = $item->rule_escalation->first();
        if ($rs) {
            $recipientCount = $rs->rule_escalation_recipient->count();
            if ($recipientCount) {
                $info->addHtml(new RuleEscalationRecipientBadge(
                    $rs->rule_escalation_recipient->first(),
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

        $escalationCount = $item->rule_escalation->count();
        if ($escalationCount > 1) {
            $footer->addHtml(new Icon('code-branch'), new Text($escalationCount));
        }
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }
}
