<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\NotificationTransmissionState;
use Icinga\Module\Notifications\Model\NotificationHistory;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;

/** @implements ItemRenderer<NotificationHistory> */
class NotificationRenderer implements ItemRenderer
{
    use Translation;

    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('notification-history');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
        $visual->addHtml($item->state->getIcon());
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        if ($layout === 'header') {
            $content = new HtmlElement('span', Attributes::create(['class' => 'subject']));
        } else {
            $content = new Link(
                null,
                Url::fromPath('notifications/notification', ['id' => $item->id]),
                ['class' => 'subject']
            );
        }

        $title->addHtml($content->addHtml(Text::create($this->buildMessage($item))));
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
        $caption->addHtml(Text::create($item->event_message));
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        $info->addHtml(new TimeAgo($item->triggered_at->getTimestamp()));
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
        if (isset($item->contactgroup_id)) {
            $icon = new Icon(Icons::CONTACTGROUP, [
                'title' => $this->translate('Notified as member of this contact group')
            ]);
            $name = $item->contactgroup->name ?? $this->translate('unknown');
        } elseif (isset($item->schedule_id)) {
            $icon = new Icon(Icons::SCHEDULE, [
                'title' => $this->translate('Notified as member of this schedule')
            ]);
            $name = $item->schedule->name ?? $this->translate('unknown');
        } else {
            return;
        }

        $footer->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'membership']),
                $icon,
                Text::create($name)
            )
        );
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }

    /**
     * Build the message for the notification history item
     *
     * @param NotificationHistory $item
     *
     * @return string
     */
    protected function buildMessage(NotificationHistory $item): string
    {
        $pluginName = $item->channel->available_channel_type->name ?? $this->translate('unknown');
        $contactName = $item->contact->full_name ?? $this->translate('unknown');

        $format = match ($item->state) {
            NotificationTransmissionState::SENT => $this->translate('Sent notification via %s to %s'),
            NotificationTransmissionState::FAILED => $this->translate(
                'Failed to send notification via %s to %s'
            )
        };

        return sprintf($format, $pluginName, $contactName);
    }
}
