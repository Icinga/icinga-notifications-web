<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use DateTime;
use Exception;
use Icinga\Application\Config;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\Severity;
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
use ipl\Web\Widget\HorizontalKeyValue;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\Time;

class NotificationDetail extends BaseHtmlElement
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
            'pre',
            Attributes::create([
                'class' => ['message', 'collapsible'],
                'data-visible-height' => 100
            ]),
            Text::create(
                substr(
                    $this->notificationHistory->event_message,
                    0,
                    (int) Config::module('notifications')
                        ->get('settings', 'event_message_character_limit', 10000)
                )
            )
        );

        CopyToClipboard::attachTo($message);

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Message'))),
            $message
        ];
    }

    protected function createRecipient(): HorizontalKeyValue
    {
        $contact = $this->notificationHistory->contact;
        $name = $contact->full_name ?? $this->translate('unknown');

        $recipient = new HtmlElement(
            'div',
            Attributes::create(['class' => 'recipient']),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'contact']),
                new Icon(Icons::USER, ['title' => $this->translate('Notified contact')]),
                isset($contact->id) && $this->getAuth()->hasPermission('notifications/config/contacts')
                    ? new Link($name, Links::contact($contact->id), ['data-base-target' => '_next'])
                    : Text::create($name)
            )
        );

        $membership = $this->createMembership();
        if ($membership !== null) {
            $recipient->addHtml(FormattedString::create($this->translate('(Member of %s)'), $membership));
        }

        return new HorizontalKeyValue(
            $this->translate('Recipient'),
            $recipient
        );
    }

    protected function createMembership(): ?ValidHtml
    {
        if (isset($this->notificationHistory->contactgroup_id)) {
            $icon = new Icon(Icons::CONTACTGROUP, ['title' => $this->translate('Contact group')]);
            $name = $this->notificationHistory->contactgroup->name ?? $this->translate('unknown');
            $url = isset($this->notificationHistory->contactgroup->id)
                && $this->getAuth()->hasPermission('notifications/config/contacts')
                ? Links::contactGroup($this->notificationHistory->contactgroup->id)
                : null;
        } elseif (isset($this->notificationHistory->schedule_id)) {
            $icon = new Icon(Icons::SCHEDULE, ['title' => $this->translate('Schedule')]);
            $name = $this->notificationHistory->schedule->name ?? $this->translate('unknown');
            $url = isset($this->notificationHistory->schedule->id)
                && $this->getAuth()->hasPermission('notifications/config/schedules')
                ? Links::schedule($this->notificationHistory->schedule->id)
                : null;
        } else {
            return null;
        }

        return new HtmlElement(
            'span',
            Attributes::create(['class' => 'membership']),
            $icon,
            $url !== null ? new Link($name, $url, ['data-base-target' => '_next']) : Text::create($name)
        );
    }

    protected function createChannel(): HorizontalKeyValue
    {
        $channel = $this->notificationHistory->channel;

        return new HorizontalKeyValue(
            $this->translate('Channel'),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'channel']),
                isset($channel->id) && $this->getAuth()->hasPermission('config/modules')
                    ? new Link($channel->name, Links::channel($channel->id), ['data-base-target' => '_next'])
                    : Text::create($channel->name ?? $this->translate('unknown')),
                Text::create(sprintf(' (%s)', $this->notificationHistory->channel_label ?? $this->translate('deleted')))
            )
        );
    }

    protected function createTransmission(): HorizontalKeyValue
    {
        $state = $this->notificationHistory->state;

        return new HorizontalKeyValue(
            $this->translate('Transmission'),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'transmission']),
                $state->getIcon(),
                Text::create($state->getLabel()),
                new Time($this->notificationHistory->triggered_at)
            )
        );
    }

    protected function createDelivery(): array
    {
        $delivery = $this->notificationHistory->delivery_result;
        if ($delivery === null) {
            return [];
        }

        $deliveryData = json_decode($delivery, true);
        $deliveryDetail = array_map(function ($deliveryItem) {
            $value = match ($deliveryItem['type']) {
                'boolean' => $deliveryItem['value'] ? $this->translate('Yes') : $this->translate('No'),
                'severity' => $this->createSeverity($deliveryItem),
                'url' => $this->createLink($deliveryItem),
                'datetime' => $this->createDateTime($deliveryItem),
                default => $deliveryItem['value']
            };

            return new HorizontalKeyValue($deliveryItem['label'], $value);
        }, $deliveryData);

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Delivery Result'))),
            new HtmlElement('div', Attributes::create(['class' => 'delivery']), ...$deliveryDetail)
        ];
    }

    protected function createSeverity(array $item): ValidHtml
    {
        $severity = Severity::tryFrom((string) $item['value']);
        if ($severity === null) {
            return new Text($item['value']);
        }

        return new HtmlElement(
            'span',
            Attributes::create(['class' => 'severity']),
            $severity->getIcon(),
            Text::create($severity->getLabel())
        );
    }

    protected function createDateTime(array $item): ValidHtml
    {
        try {
            return new Time(new DateTime($item['value']));
        } catch (Exception) {
            return new Text($item['value']);
        }
    }

    protected function createLink(array $item): ValidHtml
    {
        if (in_array(strtolower((string) parse_url($item['value'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return new Link(
                $item['anchor_text'] ?? $item['value'],
                $item['value'],
                ['target' => '_blank', 'rel' => 'noopener noreferrer']
            );
        }

        return new Text($item['value']);
    }

    protected function createIncident(): array
    {
        if (
            ! isset($this->notificationHistory->incident_id)
            || ! $this->getAuth()->hasPermission('notifications/view/incidents')
        ) {
            return [];
        }

        // Prepare incident so that the object relation is available required by the incident renderer
        $this->notificationHistory->incident->object = $this->notificationHistory->object;

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Related Incident'))),
            new ObjectList([$this->notificationHistory->incident], new IncidentRenderer())
        ];
    }

    protected function assemble(): void
    {
        $this->add([
            new HtmlElement('h2', content: Text::create($this->translate('Delivery Overview'))),
            $this->createRecipient(),
            $this->createChannel(),
            $this->createTransmission(),
            $this->createDelivery(),
            $this->createIncident(),
            $this->createMessage()
        ]);
    }
}
