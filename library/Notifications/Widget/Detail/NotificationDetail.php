<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\EscalationConditionDescriber;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\IncidentHistoryType;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEscalation;
use Icinga\Module\Notifications\View\IncidentRenderer;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Stdlib\Filter;
use ipl\Web\Widget\CopyToClipboard;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Time;

class NotificationDetail extends BaseHtmlElement
{
    use Translation;

    protected NotificationHistory $notificationHistory;

    protected $defaultAttributes = [
        'class'                         => 'notification-history-detail',
        'data-pdfexport-page-breaks-at' => 'h2'
    ];

    protected $tag = 'div';

    public function __construct(NotificationHistory $notificationHistory)
    {
        $this->notificationHistory = $notificationHistory;
    }

    protected function createMessage(): array
    {
        $message = new HtmlElement(
            'div',
            Attributes::create([
                'class' => ['message', 'collapsible'],
                'data-visible-height' => 100
            ]),
            Text::create($this->notificationHistory->event_message)
        );

        CopyToClipboard::attachTo($message);

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Message'))),
            $message
        ];
    }

    protected function createRecipient(): array
    {
        $recipient = new HtmlElement(
            'div',
            Attributes::create(['class' => 'recipient']),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'contact']),
                new Icon(Icons::USER, ['title' => $this->translate('Notified contact')]),
                Text::create($this->notificationHistory->contact->full_name ?? $this->translate('unknown'))
            )
        );

        $membership = $this->createMembership();
        if ($membership !== null) {
            $recipient->addHtml(FormattedString::create($this->translate('(Member of %s)'), $membership));
        }

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Recipient'))),
            $recipient
        ];
    }

    protected function createMembership(): ?ValidHtml
    {
        if (isset($this->notificationHistory->contactgroup_id)) {
            $icon = new Icon(Icons::CONTACTGROUP, ['title' => $this->translate('Contact group')]);
            $name = $this->notificationHistory->contactgroup->name ?? $this->translate('unknown');
        } elseif (isset($this->notificationHistory->schedule_id)) {
            $icon = new Icon(Icons::SCHEDULE, ['title' => $this->translate('Schedule')]);
            $name = $this->notificationHistory->schedule->name ?? $this->translate('unknown');
        } else {
            return null;
        }

        return new HtmlElement(
            'span',
            Attributes::create(['class' => 'membership']),
            $icon,
            Text::create($name)
        );
    }

    protected function createChannel(): array
    {
        $channel = $this->notificationHistory->channel;

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Channel'))),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'channel']),
                $channel->getIcon(),
                Text::create(sprintf(
                    '%s (%s)',
                    $channel->name ?? $this->translate('unknown'),
                    $channel->type ?? $this->translate('deleted')
                ))
            )
        ];
    }

    protected function createTransmission(): array
    {
        $state = $this->notificationHistory->state;

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Transmission'))),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'transmission']),
                $state->getIcon(),
                Text::create($state->getLabel()),
                new Time($this->notificationHistory->triggered_at)
            )
        ];
    }

    protected function createIncident(): array
    {
        return [
            new HtmlElement('h2', content: Text::create($this->translate('Related Incident'))),
            new ObjectList([$this->notificationHistory->incident], new IncidentRenderer())
        ];
    }

    protected function createReason(): array
    {
//        [$reason, $rule, $ruleEscalation] = $this->resolveTriggerChain();
        $triggerChain = new HtmlElement(
            'div',
            Attributes::create(['class' => 'trigger-chain']),
//            new HtmlElement(
//                'span',
//                Attributes::create(['class' => 'item']),
//                Text::create($reason->getLabel())
//            )
        );

//        if ($rule !== null) {
//            $triggerChain->addHtml(
//                new HtmlElement(
//                    'span',
//                    Attributes::create(['class' => 'item']),
//                    Text::create(sprintf($this->translate('Rule %s matched'), $rule->name))
//                ),
//                new HtmlElement(
//                    'span',
//                    Attributes::create(['class' => 'item']),
//                    Text::create(sprintf(
//                        $this->translate('Escalation triggered (%s)'),
//                        EscalationConditionDescriber::describe($ruleEscalation?->condition)
//                    ))
//                )
//            );
//        }

        $triggerChain->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'item']),
                $this->notificationHistory->state->getIcon()
            )
        );

        $query = $this->notificationHistory->skipped
            ->with([
                'contactgroup',
                'schedule',
                'rule',
                'rule_escalation'
            ]);
        $skip = [];
        foreach ($query as $skipped) {
            if (isset($skipped->rule_escalation->id)) {
                // An escalation is only named optionally, its condition describes it otherwise
                $escalation = $skipped->rule_escalation->name
                    ?? EscalationConditionDescriber::describe($skipped->rule_escalation->condition);
            } else {
                // Don't describe a null condition, the describer would call it 'Immediately'
                $escalation = $this->translate('unknown');
            }

            $ruleName = $skipped->rule->name ?? $this->translate('unknown');

            if (isset($skipped->contactgroup_id)) {
                $text = sprintf(
                    $this->translate('Rule: %s, Escalation: %s, ContactGroup: %s'),
                    $ruleName,
                    $escalation,
                    $skipped->contactgroup->name ?? $this->translate('unknown')
                );
            } elseif (isset($skipped->schedule_id)) {
                $text = sprintf(
                    $this->translate('Rule: %s, Escalation: %s, Schedule: %s'),
                    $ruleName,
                    $escalation,
                    $skipped->schedule->name ?? $this->translate('unknown')
                );
            } else {
                $text = sprintf(
                    $this->translate('Rule: %s, Escalation: %s, Contact: %s'),
                    $ruleName,
                    $escalation,
                    $this->notificationHistory->contact->full_name ?? $this->translate('unknown')
                );
            }

            $skip[] = new HtmlElement('li', Attributes::create(['class' => 'popup-item']), Text::create($text));
        }

        if (! empty($skip)) {
            $triggerChain->addHtml(
                new HtmlElement(
                    'ul',
                    Attributes::create(['class' => 'skipped']),
                    Text::create(sprintf($this->translate('(%s Skipped)'), count($skip))),
                    new HtmlElement('div', Attributes::create(['class' => ['popup']]), ...$skip)
                )
            );
        }

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Trigger Chain'))),
            $triggerChain
        ];
    }

    /**
     * Get the root cause and the matching rule and escalation that triggered the notification
     *
     * @return array{IncidentHistoryType, ?Rule, ?RuleEscalation}
     */
//    protected function resolveTriggerChain(): array
//    {
//        // TODO: implement properly once the daemon supports it
//        $reason = $this->notificationHistory->incident_history->type;
//        $rule = null;
//        $ruleEscalation = null;
//
//        $triggeredBy = $this->notificationHistory->incident_history->triggered_by_id;
//        while ($triggeredBy !== null) {
//            $node = IncidentHistory::on(Database::get())
//                ->with(['rule', 'rule_escalation'])
//                ->filter(Filter::equal('id', $triggeredBy))
//                ->first();
//
//            if ($node === null) {
//                break;
//            }
//
//            if ($node->rule_id !== null) {
//                $rule = $node->rule;
//            }
//
//            if ($node->rule_escalation_id !== null) {
//                $ruleEscalation = $node->rule_escalation;
//            }
//
//            $reason = $node->type;
//            $triggeredBy = $node->triggered_by_id;
//        }
//
//        return [$reason, $rule, $ruleEscalation];
//    }

    protected function assemble(): void
    {
        $this->add([
            $this->createRecipient(),
            $this->createChannel(),
            $this->createTransmission(),
            $this->createIncident(),
            $this->createMessage(),
            $this->createReason()
        ]);
    }
}
