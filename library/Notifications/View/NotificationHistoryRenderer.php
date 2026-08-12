<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Model\NotificationHistory;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Url;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;

/** @implements ItemRenderer<NotificationHistory> */
class NotificationHistoryRenderer implements ItemRenderer
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
                Url::fromPath('notifications/notification-history', ['id' => $item->id]),
                ['class' => 'subject']
            );
        }

        $title->addHtml($content->addHtml(Text::create($this->buildMessage($item))));
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
        //TODO: this is nullable
        $caption->addHtml(Text::create($item->message));
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        $info->addHtml(new TimeAgo($item->triggered_at->getTimestamp()));
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
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
        return match (true) {
            (bool) $item->contactgroup_id => sprintf(
                $this->translate('Notification via %s (%s) %s for contact %s as member of contact group %s'),
                $item->channel->name,
                $item->channel->type,
                //TODO: this doesn't work...
                $this->translate($item->state->getValue()),
                $item->contact->full_name,
                $item->contactgroup->name
            ),
            (bool) $item->schedule_id => sprintf(
                $this->translate('Notification via %s (%s) %s for contact %s as member of schedule %s'),
                $item->channel->name,
                $item->channel->type,
                $this->translate($item->state->getValue()),
                $item->contact->full_name,
                $item->schedule->name
            ),
            default => sprintf(
                $this->translate('Notification via %s (%s) %s for contact %s'),
                $item->channel->name,
                $item->channel->type,
                $this->translate($item->state->getValue()),
                $item->contact->full_name
            )
        };
    }
}
