<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Widget\EventSourceBadge;
use Icinga\Module\Notifications\Widget\ItemList\IncidentContactList;
use Icinga\Module\Notifications\Widget\ItemList\IncidentHistoryList;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Html\Table;
use ipl\Stdlib\Filter;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\StateBall;

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
        //TODO(sd): Add hook implementation
        $list = Html::tag('ul', ['class' => ['item-list', 'minimal', 'action-list'], 'data-base-target' => '_next']);

        if ($this->incident->object->service) {
            $objectLink = Html::sprintf(
                t('%s on %s', '<service> on <host>'),
                new Link($this->incident->object->service, $this->incident->object->url, ['class' => 'subject']),
                Html::tag('span', ['class' => 'subject'], $this->incident->object->host)
            );
        } else {
            $objectLink = new Link($this->incident->object->host, $this->incident->object->url, ['class' => 'subject']);
        }

        $list->add(Html::tag(
            'li',
            ['class' => 'list-item', 'data-action-item' => true],
            [ //TODO(sd): fix stateball
                Html::tag(
                    'div',
                    ['class' => 'visual'],
                    new StateBall('down', StateBall::SIZE_LARGE)
                ),
                Html::tag(
                    'div',
                    ['class' => 'main'],
                    Html::tag('header')->add(Html::tag('div', ['class' => 'title'], $objectLink))
                )
            ]
        ));

        return [
            Html::tag('h2', t('Object')),
            $list
        ];
    }

    protected function createHistory()
    {
        return [
            Html::tag('h2', t('Incident History')),
            new IncidentHistoryList(
                $this->incident->incident_history
                    ->with([
                        'event',
                        'event.object',
                        'event.object.source',
                        'contact',
                        'rule',
                        'rule_escalation',
                        'contactgroup',
                        'schedule'
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
