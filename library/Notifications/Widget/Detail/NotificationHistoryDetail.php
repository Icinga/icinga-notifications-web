<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\EscalationConditionDescriber;
use Icinga\Module\Notifications\Common\NotificationTransmissionReason;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\View\IncidentRenderer;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Widget\CopyToClipboard;
use ipl\Web\Widget\Time;

class NotificationHistoryDetail extends BaseHtmlElement
{
    use Auth;
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
            Text::create($this->notificationHistory->message)
        );

        CopyToClipboard::attachTo($message);

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Message'))),
            $message
        ];
    }

    protected function createRecipient(): array
    {
        $recipient = match (true) {
            (bool) $this->notificationHistory->contactgroup_id => sprintf(
                $this->translate('Contact %s of Contactgroup %s'),
                $this->notificationHistory->contact->full_name,
                $this->notificationHistory->contactgroup->name
            ),
            (bool) $this->notificationHistory->schedule_id => sprintf(
                $this->translate('Contact %s of Schedule %s'),
                $this->notificationHistory->contact->full_name,
                $this->notificationHistory->schedule->name
            ),
            (bool) $this->notificationHistory->contact_id => sprintf(
                $this->translate('Contact %s'),
                $this->notificationHistory->contact->full_name
            )
        };

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Recipient'))),
            $recipient
        ];
    }

    protected function createChannel(): array
    {
        return [
            new HtmlElement('h2', content: Text::create($this->translate('Channel'))),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'channel']),
                $this->notificationHistory->channel->getIcon(),
                Text::create(sprintf(
                    '%s (%s)',
                    $this->notificationHistory->channel->name,
                    $this->notificationHistory->channel->type
                ))
            )
        ];

        //TODO: maybe change the icon of type webhook, it looks like mail
    }

    protected function createTime(): array
    {
        return [
            new HtmlElement('h2', content: Text::create($this->translate('Triggered at'))),
            new Time($this->notificationHistory->triggered_at)
        ];
    }

    protected function createIncident(): ?array
    {
        if ($this->notificationHistory->incident_id === null) {
            return null;
        }

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Related Incident'))),
            new ObjectList([$this->notificationHistory->incident], new IncidentRenderer())
        ];
    }

    protected function createReason(): array
    {
        $triggerChain = new HtmlElement(
            'div',
            Attributes::create(['class' => 'trigger-chain']),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'item']),
                Text::create($this->notificationHistory->reason->getLabel())
            )
        );

        if (
            ! in_array(
                $this->notificationHistory->reason,
                [NotificationTransmissionReason::MUTED, NotificationTransmissionReason::UNMUTED],
                true
            )
        ) {
            $triggerChain->addHtml(
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'item']),
                    Text::create(sprintf($this->translate('Rule %s matched'), $this->notificationHistory->rule->name))
                ),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'item']),
                    Text::create(sprintf(
                        $this->translate('Escalation triggered (%s)'),
                        EscalationConditionDescriber::describe($this->notificationHistory->rule_escalation->condition)
                    ))
                ),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'item']),
                    $this->notificationHistory->state->getIcon()
                )
            );
        }

        $query = $this->notificationHistory->skipped
            ->with([
                'contactgroup',
                'schedule',
                'rule',
                'rule_escalation'
            ]);
        $skip = [];
        foreach ($query as $skipped) {
            if (isset($skipped->contactgroup_id)) {
                //TODO: add fallback in case rule_escalation->name is null
                $text = sprintf(
                    $this->translate('Rule: %s, Escalation: %s, ContactGroup: %s'),
                    $skipped->rule->name,
                    $skipped->rule_escalation->name,
                    $skipped->contactgroup->name
                );
            } elseif (isset($skipped->schedule_id)) {
                $text = sprintf(
                    $this->translate('Rule: %s, Escalation: %s, Schedule: %s'),
                    $skipped->rule->name,
                    $skipped->rule_escalation->name,
                    $skipped->schedule->name
                );
            } else {
                $text = sprintf(
                    $this->translate('Rule: %s, Escalation: %s, Contact: %s'),
                    $skipped->rule->name,
                    $skipped->rule_escalation->name,
                    $this->notificationHistory->contact->full_name
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

    protected function assemble(): void
    {
        $this->add([
            $this->createMessage(),
            $this->createRecipient(),
            $this->createChannel(),
            $this->createTime(),
            $this->createReason(),
            $this->createIncident()
        ]);
    }
}
