<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
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
use ipl\Web\Widget\EmptyState;

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
            ->orderBy('role', SORT_DESC);

        foreach ($query as $incident_contact) {
            if (isset($incident_contact->contact->id)) {
                $contact = $incident_contact->contact;
                $contact->role = $incident_contact->role;

                $contacts[] = $contact;
            }
        }

        $disableContactLink = ! $this->getAuth()->hasPermission('notifications/view/contacts')
            || ! $this->getAuth()->hasPermission('notifications/config/contacts');

        return [
            Html::tag('h2', $this->translate('Subscribers')),
            (new ObjectList($contacts, (new IncidentContactRenderer())->disableContactLink($disableContactLink)))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled($disableContactLink)
        ];
    }

    /** @return ValidHtml[] */
    protected function createRelatedObject(): array
    {
        $objectUrl = ObjectsRendererHook::renderObjectLink($this->incident->object);

        if (! $objectUrl) {
            return [];
        }

        return [
            new HtmlElement('h2', null, Text::create($this->translate('Related Object'))),
            $objectUrl
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
            Html::tag('h2', $this->translate('Incident History')),
            (new ObjectList($query, new IncidentHistoryRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setDetailActionsDisabled()
        ];
    }

    /** @return ValidHtml[] */
    protected function createSource(): array
    {
        if (! isset($this->incident->object->source->name)) {
            return [
                Html::tag('h2', $this->translate('Event Source')),
                new EmptyState($this->translate('No source information available'))
            ];
        }

        $list = new HtmlElement('ul', Attributes::create(['class' => 'source-list']));
        $list->addHtml(new HtmlElement('li', null, new EventSourceBadge($this->incident->object->source)));

        return [
            Html::tag('h2', $this->translate('Event Source')),
            $list
        ];
    }

    protected function assemble(): void
    {
        $this->add([
            $this->createContacts(),
            $this->createHistory(),
            $this->createRelatedObject(),
            $this->createSource(),
        ]);
    }
}
