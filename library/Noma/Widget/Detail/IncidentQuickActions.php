<?php


/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\Detail;

use DateTime;
use Exception;
use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Common\Icons;
use Icinga\Module\Noma\Model\Incident;
use Icinga\Module\Noma\Model\IncidentContact;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Widget\Icon;

class IncidentQuickActions extends Form
{
    use CsrfCounterMeasure;

    protected $defaultAttributes = [
        'class' => ['inline', 'quick-actions'],
        'name' => 'incident-quick-actions'
    ];

    /** @var Incident */
    protected $incident;

    /** @var int Current logged-in user's id */
    protected $currentUserId;

    public function __construct(Incident $incident, int $currentUserId)
    {
        $this->incident = $incident;
        $this->currentUserId = $currentUserId;
    }

    public function hasBeenSubmitted(): bool
    {
        return $this->hasBeenSent() && $this->getPressedSubmitElement();
    }

    protected function assembleManageButton(): void
    {
        $this->addElement(
            'submitButton',
            'manage',
            [
                'class' => ['control-button', 'spinner'],
                'label' => [new Icon(Icons::MANAGE), t('Manage')],
                'title' => t('Add yourself as manager of this incident')
            ]
        );
    }

    protected function assembleUnmanageButton(): void
    {
        $this->addElement(
            'submitButton',
            'unmanage',
            [
                'class' => ['control-button', 'spinner'],
                'label' => [new Icon(Icons::MANAGE), t('Unmanage')],
                'title' => t('Remove yourself as manager of this incident')
            ]
        );
    }

    protected function assembleSubscribeButton(): void
    {
        $this->addElement(
            'submitButton',
            'subscribe',
            [
                'class' => ['control-button', 'spinner'],
                'label' => [new Icon(Icons::SUBSCRIBE), t('Subscribe')],
                'title' => t('Subscribe to this incident')
            ]
        );
    }

    protected function assembleUnsubscribeButton(): void
    {
        $this->addElement(
            'submitButton',
            'unsubscribe',
            [
                'class' => ['control-button', 'spinner'],
                'label' => [new Icon(Icons::SUBSCRIBE), t('Unubscribe')],
                'title' => t('Unsubscribe from this incident')
            ]
        );
    }

    protected function assemble()
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));
        $incidentContact = $this->fetchIncidentContact();
        $role = $incidentContact ? $incidentContact->role : null;

        switch ($role) {
            case null:
            case 'recipient':
                $this->assembleManageButton();
                $this->assembleSubscribeButton();
                break;
            case 'manager':
                $this->assembleUnmanageButton();
                break;

            case 'subscriber':
                $this->assembleManageButton();
                $this->assembleUnsubscribeButton();
                break;
        }
    }

    protected function onSuccess()
    {
        $incidentContact = $this->fetchIncidentContact();
        $pressedButton = $this->getPressedSubmitElement()->getName();

        switch ($pressedButton) {
            case 'manage':
                $this->addEntry($incidentContact, 'manager');
                break;
            case 'unmanage':
                $this->removeEntry($incidentContact, 'manager');
                break;
            case 'subscribe':
                $this->addEntry($incidentContact, 'subscriber');
                break;
            case 'unsubscribe':
                $this->removeEntry($incidentContact, 'subscriber');
                break;
        }
    }

    /**
     * Add the incident's contact role of given contact
     *
     * @param ?IncidentContact $incidentContact The incident contact to add
     * @param string $roleName The role to add
     *
     * @return void
     */
    protected function addEntry(?IncidentContact $incidentContact, string $roleName): void
    {
        Database::get()->beginTransaction();
        try {
            if ($incidentContact) {
                Database::get()->update(
                    'incident_contact',
                    ['role' => $roleName],
                    [
                        'contact_id = ?'    => $incidentContact->contact_id,
                        'incident_id = ?'   => $this->incident->id
                    ]
                );
            } else {
                Database::get()->insert('incident_contact', [
                    'incident_id'   => $this->incident->id,
                    'contact_id'    => $this->currentUserId,
                    'role'          => $roleName
                ]);
            }

            $msg = $roleName === 'manager' ? 'started managing' : 'subscribed';
            $this->updateHistory($incidentContact, $msg, $roleName);
        } catch (Exception $e) {
            Database::get()->rollBackTransaction();
            Notification::error(sprintf(t('Failed to add role as %s'), $roleName));
        }

        Database::get()->commitTransaction();
        Notification::success(sprintf(t('Successfully added as %s'), $roleName));
    }

    /**
     * Remove the incident's contact role of given contact
     *
     * @param IncidentContact $incidentContact The incident contact to remove
     * @param string $roleName The role to remove
     *
     * @return void
     */
    protected function removeEntry(IncidentContact $incidentContact, string $roleName): void
    {
        Database::get()->beginTransaction();
        try {
            Database::get()->delete('incident_contact', [
                'incident_id = ?'   => $this->incident->id,
                'contact_id = ?'    => $incidentContact->contact_id,
                'role = ?'          => $roleName
            ]);

            $msg = $roleName === 'manager' ? 'stopped managing' : 'unsubscribed';
            $this->updateHistory($incidentContact, $msg);
        } catch (Exception $e) {
            Database::get()->rollBackTransaction();
            Notification::error(
                $roleName === 'manager'
                    ? t('Failed to remove role manager')
                    : t('Failed to unsubscribe'));
        }

        Database::get()->commitTransaction();
        Notification::success(
            $roleName === 'manager'
                ? t('Successfully removed role manager')
                : t('Successfully unsubscribed')
        );
    }

    /**
     * Update the incident history
     *
     * @param ?IncidentContact $incidentContact
     * @param string $msg
     * @param string|null $newRole
     *
     * @return void
     */
    protected function updateHistory(?IncidentContact $incidentContact, string $msg, string $newRole = null): void
    {
        $oldRole = null;
        $contactId = $this->currentUserId;
        if ($incidentContact) {
            $oldRole = $incidentContact->role;
            $contactId = $incidentContact->contact_id;
        }

        Database::get()->insert(
            'incident_history',
            [
                'incident_id'           => $this->incident->id,
                'contact_id'            => $contactId,
                'type'                  => 'recipient_role_changed',
                'new_recipient_role'    => $newRole,
                'old_recipient_role'    => $oldRole,
                'message'               => $msg,
                'time'                  => (new DateTime())->getTimestamp()
            ]
        );
    }

    /**
     * Fetch the incident's current logged-in user
     *
     * @return ?IncidentContact
     */
    protected function fetchIncidentContact(): ?IncidentContact
    {
        return IncidentContact::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('contact_id', $this->currentUserId),
                Filter::equal('incident_id', $this->incident->id))
            )
            ->first();
    }
}
