<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use ArrayIterator;
use Icinga\Module\Icingadb\Model\CustomvarFlat;
use Icinga\Module\Icingadb\Widget\Detail\CustomVarTable;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Behavior\IcingaCustomVars;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\IncidentContactRenderer;
use Icinga\Module\Notifications\View\IncidentHistoryRenderer;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\Table;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Stdlib\Filter;
use ipl\Web\Layout\MinimalItemLayout;

class IncidentDetail extends BaseHtmlElement
{
    use Auth;
    use Translation;

    /** @var Incident */
    protected $incident;

    protected $defaultAttributes = [
        'class'                         => 'incident-detail',
        'data-pdfexport-page-breaks-at' => 'h2'
    ];

    protected $tag = 'div';

    public function __construct(Incident $incident)
    {
        $this->incident = $incident;
    }

    protected function createContacts()
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

    protected function createRelatedObject()
    {
        $objectUrl = ObjectsRendererHook::renderObjectLink($this->incident->object);

        if (! $objectUrl) {
            return [];
        }

        return [
            new HtmlElement('h2', null, Text::create(t('Related Object'))),
            $objectUrl
        ];
    }

    protected function createHistory()
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

    protected function createSource()
    {
        $list = new HtmlElement('ul', Attributes::create(['class' => 'source-list']));
        $list->addHtml(new HtmlElement('li', null, new EventSourceBadge($this->incident->object->source)));

        return [
            Html::tag('h2', t('Event Source')),
            $list
        ];
    }

    protected function createObjectTag(): array
    {
        $tags = [];
        $hostCustomvars = [];
        $serviceCustomvars = [];
        foreach ($this->incident->object->object_extra_tag as $extraTag) {
            if (str_starts_with($extraTag->tag, IcingaCustomVars::HOST_PREFIX)) {
                $name = substr($extraTag->tag, strlen(IcingaCustomVars::HOST_PREFIX));
                $name = preg_replace('/(\[\d*])/', '.\1', $name);

                $hostCustomvars[] = (object) [
                    'flatname' => $name,
                    'flatvalue' => $extraTag->value
                ];
            } elseif (str_starts_with($extraTag->tag, IcingaCustomVars::SERVICE_PREFIX)) {
                $name = substr($extraTag->tag, strlen(IcingaCustomVars::SERVICE_PREFIX));
                $name = preg_replace('/(\[\d*])/', '.\1', $name);

                $serviceCustomvars[] = (object) [
                    'flatname' => $name,
                    'flatvalue' => $extraTag->value
                ];
            } else {
                $tags[] = Table::row([$extraTag->tag, $extraTag->value]);
            }
        }

        $result = [];

        if (! empty($tags) || ! empty($hostCustomvars) || ! empty($serviceCustomvars)) {
            $result[] = new HtmlElement('h2', null, new Text(t('Object Tags')));
        }

        if (! empty($tags)) {
            $result[] = (new Table())->addHtml(...$tags)->addAttributes(['class' => 'object-tags-table']);
        }

        // TODO: Drop the following custom variable handling once the final source integration is ready

        if (! empty($hostCustomvars)) {
            $result[] = new HtmlElement('h3', null, new Text('Host Custom Variables'));
            $result[] = new HtmlElement(
                'div',
                Attributes::create(['class' => ['icinga-module', 'module-icingadb']]),
                new CustomVarTable((new CustomvarFlat())->unFlattenVars(new ArrayIterator($hostCustomvars)))
            );
        }

        if (! empty($serviceCustomvars)) {
            $result[] = new HtmlElement('h3', null, new Text('Service Custom Variables'));
            $result[] = new HtmlElement(
                'div',
                Attributes::create(['class' => ['icinga-module', 'module-icingadb']]),
                new CustomVarTable((new CustomvarFlat())->unFlattenVars(new ArrayIterator($serviceCustomvars)))
            );
        }

        return $result;
    }

    protected function assemble()
    {
        $this->add([
            $this->createContacts(),
            $this->createHistory(),
            $this->createRelatedObject(),
            $this->createSource(),
            $this->createObjectTag(),
        ]);
    }
}
