<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\EventRule;

use Icinga\Application\Logger;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEntry;
use Icinga\Module\Notifications\Util\RuleSerializer;
use ipl\Html\FormattedString;
use ipl\Html\HtmlDocument;
use ipl\Html\Text;
use ipl\I18n\Translation;
use Throwable;

/**
 * Message describing that a rule escalated, i.e. one of its entries triggered
 *
 * The escalation is described by the condition of the rule entry, in the past tense. An entry without
 * a condition is described as an immediate escalation. If the condition cannot be decoded, the failure
 * is logged and a generic message is shown instead.
 */
class EscalationTriggered extends HtmlDocument
{
    use Translation;

    /**
     * Create a message for the given rule and the entry that triggered
     *
     * @param ?Rule $rule
     * @param ?RuleEntry $ruleEntry
     */
    public function __construct(
        protected ?Rule $rule,
        protected ?RuleEntry $ruleEntry
    ) {
    }

    protected function assemble(): void
    {
        if (! isset($this->rule->name)) {
            $this->addHtml(Text::create($this->translate('Unknown rule escalated')));

            return;
        }

        if ($this->ruleEntry?->condition === null) {
            $this->addHtml(Text::create(
                sprintf($this->translate('Rule %s escalated immediately'), $this->rule->name)
            ));

            return;
        }

        try {
            $this->addHtml(FormattedString::create(
                $this->translate('Rule %s escalated: %s'),
                $this->rule->name,
                EscalationCondition::fromQueryString(
                    RuleSerializer::decode($this->ruleEntry->condition)['qs']
                )->usePastTense()
            ));
        } catch (Throwable $e) {
            Logger::error('Failed to describe the condition of rule_entry %d: %s', $this->ruleEntry->id, $e);

            $this->addHtml(Text::create(sprintf(
                $this->translate('Rule %s escalated: Unable to describe the escalation condition'),
                $this->rule->name
            )));
        }
    }
}
