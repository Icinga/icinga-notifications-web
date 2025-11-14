<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Widget\Detail\IncidentDetail;
use Icinga\Module\Notifications\Widget\Detail\IncidentQuickActions;
use Icinga\Module\Notifications\Widget\Detail\ObjectHeader;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class IncidentController extends CompatController
{
    use Auth;

    public function indexAction(): void
    {
        $this->addTitleTab(t('Incident'));

        $id = $this->params->getRequired('id');

        $query = Incident::on(Database::get())
            ->with(['object', 'object.source'])
            ->withColumns('object.id_tags')
            ->filter(Filter::equal('incident.id', $id));

        $this->applyRestrictions($query);

        /** @var Incident $incident */
        $incident = $query->first();
        if ($incident === null) {
            $this->httpNotFound(t('Incident not found'));
        }

        $this->addControl(new ObjectHeader($incident));

        $this->controls->addAttributes(Attributes::create(['class' => 'incident-detail']));

        $contact = Contact::on(Database::get())
            ->columns('id')
            ->filter(Filter::equal('username', $this->Auth()->getUser()->getUsername()))
            ->first();

        if ($contact !== null) {
            $this->addControl(
                (new IncidentQuickActions($incident, $contact->id))
                    ->on(Form::ON_SUBMIT, function () use ($incident) {
                        $this->redirectNow(Links::incident($incident->id));
                    })
                    ->handleRequest($this->getServerRequest())
            );
        }

        $this->addContent(new IncidentDetail($incident));
    }
}
