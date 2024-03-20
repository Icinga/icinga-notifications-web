<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\IncidentContactList;
use Icinga\Module\Notifications\Widget\ItemList\IncidentHistoryList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\Table;

class IncidentDetail extends BaseHtmlElement
{
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
        foreach ($this->incident->incident_contact->with('contact')->orderBy('role', SORT_DESC) as $incident_contact) {
            $contact = $incident_contact->contact;
            $contact->role = $incident_contact->role;

            $contacts[] = $contact;
        }

        return [
            Html::tag('h2', t('Subscribers')),
            new IncidentContactList($contacts)
        ];
    }

    protected function createRelatedObject()
    {
        return [
            Html::tag('h2', t('Object')),
            ObjectsRendererHook::renderObjectLink($this->incident->object)
        ];
    }

    protected function createHistory()
    {
        return [
            Html::tag('h2', t('Incident History')),
            new IncidentHistoryList(
                $this->incident->incident_history
                    ->with([
                        'contact',
                        'rule',
                        'rule_escalation',
                        'contactgroup',
                        'schedule',
                        'channel'
                    ])
            )
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

    protected function createObjectTag()
    {
        $objectTags = (new Table())->addAttributes(['class' => 'object-tags-table']);

        foreach ($this->incident->object->object_extra_tag as $extraTag) {
            $objectTags->addHtml(Table::row([$extraTag->tag, $extraTag->value]));
        }

        return [
            Html::tag('h2', t('Object Tags')),
            $objectTags
        ];
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
