<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Application\ClassLoader;
use Icinga\Application\Config;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\IncidentContactRenderer;
use Icinga\Module\Notifications\View\IncidentHistoryRenderer;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Url;
use ipl\Web\Widget\CopyToClipboard;
use ipl\Web\Widget\EmptyState;
use ipl\Web\Widget\HorizontalKeyValue;
use ipl\Web\Widget\Link;

class IncidentDetail extends BaseHtmlElement
{
    use Auth;
    use Translation;

    protected Incident $incident;

    protected $defaultAttributes = [
        'class'                         => 'incident-detail',
        'data-pdfexport-page-breaks-at' => 'h2'
    ];

    protected $tag = 'div';

    public function __construct(Incident $incident)
    {
        $this->incident = $incident;
    }

    /** @return ValidHtml[] */
    protected function createNotificationRecipients(): array
    {
        $subscribers = [];
        $recipients = [];

        $query = $this->incident->incident_contact
            ->with(['contact', 'contactgroup', 'schedule'])
            ->orderBy('role', SORT_DESC);

        foreach ($query as $incident_contact) {
            if (isset($incident_contact->contact->id)) {
                $contact = $incident_contact->contact;
            } elseif (isset($incident_contact->contactgroup->id)) {
                $contact = $incident_contact->contactgroup;
            } elseif (isset($incident_contact->schedule->id)) {
                $contact = $incident_contact->schedule;
            } else {
                continue;
            }

            $contact->role = $incident_contact->role;
            if ($incident_contact->role === "subscriber" || $incident_contact->role === "manager") {
                $subscribers[] = $contact;
            } else {
                $recipients[] = $contact;
            }
        }

        $disableContactLink = ! $this->getAuth()->hasPermission('notifications/view/contacts')
            || ! $this->getAuth()->hasPermission('notifications/config/contacts');
        $disableScheduleLink = ! $this->getAuth()->hasPermission('notifications/config/schedules');

        $subscriberList = (new ObjectList($subscribers, (new IncidentContactRenderer())
                ->disableContactLink($disableContactLink)
                ->disableScheduleLink($disableScheduleLink)))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled(true)
                ->setEmptyStateMessage($this->translate('No subscribers.'))
                ->addAttributes(Attributes::create(["class" => "incident-contact-list"]));
        $recipientList = (new ObjectList($recipients, (new IncidentContactRenderer())
                ->disableContactLink($disableContactLink)
                ->disableScheduleLink($disableScheduleLink)))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled(true)
                ->setEmptyStateMessage($this->translate('No recipients.'))
                ->addAttributes(Attributes::create(["class" => "incident-contact-list"]));
        return [
            Html::tag('h2', $this->translate('Notification Recipients')),
            new HorizontalKeyValue($this->translate('Subscribers'), $subscriberList),
            new HorizontalKeyValue($this->translate('Recipients'), $recipientList),
        ];
    }

    /** @return ValidHtml[] */
    protected function createRelatedObject(): array
    {
        $object = $this->incident->object;
        $hook = null;
        foreach ($object->sources as $source) {
            $hook = SourceHookLocator::forType($source->type);
            if ($hook !== null) {
                break;
            }
        }

        $objectUrl = $hook?->createObjectLink($object->id_tags);
        if ($objectUrl === null) {
            if (! isset($object->url)) {
                return [];
            }

            $objectUrl = new Link(
                $object->name,
                Url::fromPath($object->url),
                ['data-base-target' => '_next']
            );
        } else {
            $objectUrl = new HtmlElement('div', Attributes::create([
                'data-base-target' => '_next',
                'class' => ['icinga-module', 'module-' . ClassLoader::extractModuleName($hook::class)]
            ]), $objectUrl);
        }

        return [
            new HtmlElement('h2', null, Text::create($this->translate('Related Object'))),
            $objectUrl
        ];
    }

    protected function createMessage(): array
    {
        $isEmpty = empty($this->incident->message);
        $message = new HtmlElement(
            'div',
            Attributes::create([
                'class' => ['message', $isEmpty ? 'empty' : '', 'collapsible'],
                'id' => 'persist-collapse-state',
                'data-visible-height' => 100
            ]),
            $isEmpty
                ? new EmptyState($this->translate('No message available'))
                : Text::create(
                    substr(
                        $this->incident->message,
                        0,
                        (int) Config::module('notifications')
                            ->get('settings', 'incident_message_character_limit', 10000)
                    )
                )
        );

        if (! $isEmpty) {
            CopyToClipboard::attachTo($message);
        }

        return [
            new HtmlElement('h2', content: Text::create($this->translate('Message'))),
            $message
        ];
    }

    /** @return ValidHtml[] */
    protected function createHistory(): array
    {
        $query = $this->incident->incident_history
            ->with([
                'contact',
                'rule',
                'rule_entry',
                'contactgroup',
                'schedule',
                'channel'
            ]);

        return [
            Html::tag('h2', $this->translate('Incident History')),
            (new ObjectList($query, new IncidentHistoryRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled()
        ];
    }

    /** @return ValidHtml[] */
    protected function createSources(): array
    {
        if (empty($this->incident->object->sources)) {
            return [
                Html::tag('h2', $this->translate('Event Source')),
                new EmptyState($this->translate('No source information available'))
            ];
        }

        $list = new HtmlElement('ul', Attributes::create(['class' => 'source-list']));
        foreach ($this->incident->object->sources as $source) {
            $list->addHtml(new HtmlElement('li', null, new EventSourceBadge($source)));
        }

        return [
            Html::tag('h2', $this->translate('Event Sources')),
            $list
        ];
    }

    protected function assemble(): void
    {
        $this->add([
            $this->createNotificationRecipients(),
            $this->createHistory(),
            $this->createRelatedObject(),
            $this->createMessage(),
            $this->createSources(),
        ]);
    }
}
