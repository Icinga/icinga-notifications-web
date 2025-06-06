<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

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
use ipl\Html\Table;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Web\Layout\MinimalItemLayout;

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
        $query = $this->incident->incident_contact
            ->with('contact')
            ->filter(Filter::equal('contact.deleted', 'n'))
            ->orderBy('role', SORT_DESC);

        foreach ($query as $incident_contact) {
            $contact = $incident_contact->contact;
            $contact->role = $incident_contact->role;

            $contacts[] = $contact;
        }

        return [
            Html::tag('h2', t('Subscribers')),
            (new ObjectList($contacts, new IncidentContactRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
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
                ->disableActionList()
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
        foreach ($this->incident->object->object_extra_tag as $extraTag) {
            $tags[] = Table::row([$extraTag->tag, $extraTag->value]);
        }

        if (! $tags) {
            return $tags;
        }

        return [
            new HtmlElement('h2', null, new Text(t('Object Tags'))),
            (new Table())
                ->addHtml(...$tags)
                ->addAttributes(['class' => 'object-tags-table'])
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
