<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Auth;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Common\Links;
use Icinga\Module\Noma\Model\Contact;
use Icinga\Module\Noma\Model\Incident;
use Icinga\Module\Noma\Widget\Detail\IncidentDetail;
use Icinga\Module\Noma\Widget\Detail\IncidentQuickActions;
use Icinga\Module\Noma\Widget\ItemList\IncidentList;
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
            ->with(['object'])
            ->filter(Filter::equal('incident.id', $id));

        $this->applyRestrictions($query);

        /** @var Incident $incident */
        $incident = $query->first();
        if ($incident === null) {
            $this->httpNotFound(t('Incident not found'));
        }

        $this->addControl(
            (new IncidentList($query))
                ->setNoSubjectLink()
        );

        $this->controls->addAttributes(['class' => 'incident-detail']);

        $contact = Contact::on(Database::get())
            ->columns('id')
            ->filter(Filter::equal('username', $this->Auth()->getUser()->getUsername()))
            ->first();

        if ($contact !== null) {
            $this->addControl(
                (new IncidentQuickActions($incident, $contact->id))
                    ->on(IncidentQuickActions::ON_SUCCESS, function () use ($incident) {
                        $this->redirectNow(Links::incident($incident->id));
                    })
                    ->handleRequest($this->getServerRequest())
            );
        }

        $this->addContent(new IncidentDetail($incident));
    }
}
