<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\Event;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Widget\HorizontalKeyValue;
use ipl\Web\Widget\Link;

class EventController extends CompatController
{
    use Auth;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Event'));

        $id = $this->params->getRequired('id');

        $query = Event::on(Database::get())
            ->with(['source', 'object', 'source_object', 'incident'])
            ->filter(Filter::equal('event.id', $id));

        $this->applyRestrictions($query);

        /** @var Event $event */
        $event = $query->first();
        if ($event === null) {
            $this->httpNotFound(t('Event not found'));
        }

        $objectName = $event->object->host;

        if ($event->object->service) {
            $objectName .= ' on ' . $event->object->service;
        }

        $detail = Html::tag('div', [], t('Detail'));
        $detail->add([
            new HorizontalKeyValue(t('Start Time'), $event->time->format('Y-m-d H:i:s')),
            new HorizontalKeyValue(t('Source Name'), $event->source->name),
            new HorizontalKeyValue(t('Source Type'), $event->source->type),
            new HorizontalKeyValue(t('Object Name'), $objectName),
            new HorizontalKeyValue(t('Object Url'), new Link($event->source_object->url, $event->source_object->url)),
            new HorizontalKeyValue(t('User'), $event->username),
            new HorizontalKeyValue(t('Severity'), $event->severity),
            new HorizontalKeyValue(t('Message'), $event->message),
            new HorizontalKeyValue(t('Connected Incident'), $event->incident->id)
        ]);

        $this->addContent($detail);
    }
}
