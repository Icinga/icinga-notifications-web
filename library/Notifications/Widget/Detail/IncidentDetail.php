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
use ipl\Stdlib\Filter;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Url;
use ipl\Web\Widget\CopyToClipboard;
use ipl\Web\Widget\EmptyState;
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
    protected function createContacts(): array
    {
        $contacts = [];
        $query = $this->incident->incident_contact
            ->with('contact')
            ->filter(Filter::equal('contact.deleted', 'n'))
            ->orderBy('role', SORT_DESC);

        foreach ($query as $incident_contact) {
            $contact = $incident_contact->contact;
            $contact->role = $incident_contact->role;

            $contacts[] = $contact;
        }

        $disableContactLink = ! $this->getAuth()->hasPermission('notifications/view/contacts')
            || ! $this->getAuth()->hasPermission('notifications/config/contacts');

        return [
            Html::tag('h2', t('Subscribers')),
            (new ObjectList($contacts, (new IncidentContactRenderer())->disableContactLink($disableContactLink)))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled($disableContactLink)
        ];
    }

    /** @return ValidHtml[] */
    protected function createRelatedObject(): array
    {
        $object = $this->incident->object;
        $hook = SourceHookLocator::forType($object->source->type);

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
            new HtmlElement('h2', null, Text::create(t('Related Object'))),
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
                'rule_escalation',
                'contactgroup',
                'schedule',
                'channel'
            ]);

        return [
            Html::tag('h2', t('Incident History')),
            (new ObjectList($query, new IncidentHistoryRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled()
        ];
    }

    /** @return ValidHtml[] */
    protected function createSource(): array
    {
        $list = new HtmlElement('ul', Attributes::create(['class' => 'source-list']));
        $list->addHtml(new HtmlElement('li', null, new EventSourceBadge($this->incident->object->source)));

        return [
            Html::tag('h2', t('Event Source')),
            $list
        ];
    }

    protected function assemble(): void
    {
        $this->add([
            $this->createContacts(),
            $this->createHistory(),
            $this->createRelatedObject(),
            $this->createMessage(),
            $this->createSource(),
        ]);
    }
}
