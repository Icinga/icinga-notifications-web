<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Model\NotificationHistory;
use Icinga\Module\Notifications\View\IncidentRenderer;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
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

    protected function assemble(): void
    {
        $this->add([
            $this->createMessage(),
            $this->createRecipient(),
            $this->createChannel(),
            $this->createTransmission(),
            $this->createIncident()
        ]);
    }
}
