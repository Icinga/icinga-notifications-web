<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Exception;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Integrations\V1\Incident;
use Icinga\Module\Notifications\Common\JobTracker;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Widget\Icon;

/**
 * Quick actions to change the current user's role for an incident
 *
 * The role changes are requested from the daemon, which processes them asynchronously.
 *
 * Only to be used for open incidents. The daemon applies the requests to the currently open incident of the
 * incident's object, so they have no effect for a recovered incident.
 */
class IncidentQuickActions extends Form
{
    use Auth;
    use CsrfCounterMeasure;

    protected $defaultAttributes = [
        'class' => ['inline', 'quick-actions'],
        'name' => 'incident-quick-actions'
    ];

    protected Incident $incident;

    /** @var string The type of the source that opened the incident */
    protected string $sourceType;

    public function __construct(IncidentModel $incident, JobTracker $jobTracker)
    {
        $this->incident = Incident::fromModel($incident, Database::get(), $jobTracker);
        $this->sourceType = $incident->source->type;
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

    protected function assembleUnmanageButton(bool $userIsManager): void
    {
        $this->addElement(
            'submitButton',
            'unmanage',
            [
                'class' => ['control-button', 'spinner'],
                'label' => [new Icon(Icons::UNMANAGE), t('Unmanage')],
                'title' => $userIsManager
                    ? t('Remove yourself as manager of this incident')
                    : t('Remove the current manager of this incident')
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
                'label' => [new Icon(Icons::SUBSCRIBED), t('Subscribe')],
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
                'label' => [new Icon(Icons::UNSUBSCRIBED), t('Unsubscribe')],
                'title' => t('Unsubscribe from this incident')
            ]
        );
    }

    protected function assemble(): void
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        switch ($this->incident->getRole($this->getAuth()->getUser())) {
            case null:
            case 'recipient':
                if ($this->incident->hasManager()) {
                    $this->assembleUnmanageButton(false);
                } else {
                    $this->assembleManageButton();
                }

                $this->assembleSubscribeButton();
                break;
            case 'manager':
                $this->assembleUnmanageButton(true);
                break;

            case 'subscriber':
                if ($this->incident->hasManager()) {
                    $this->assembleUnmanageButton(false);
                } else {
                    $this->assembleManageButton();
                }

                $this->assembleUnsubscribeButton();
                break;
        }

        $pendingAction = $this->incident->getPendingAction();
        if ($pendingAction !== null && $this->hasElement($pendingAction)) {
            foreach ($this->submitElements as $name => $element) {
                if ($name === $pendingAction) {
                    $element->getAttributes()
                        ->add('class', 'active')
                        ->set($this->incident->getPendingJobAttributes());
                } else {
                    $element->getAttributes()->set('disabled', true)->add('class', 'disabled');
                }
            }
        }
    }

    protected function onSuccess(): void
    {
        if ($this->incident->getPendingAction() !== null) {
            return;
        }

        $pressedButton = $this->getPressedSubmitElement()->getName();
        $username = $this->getAuth()->getUser()->getUsername();

        try {
            match ($pressedButton) {
                'manage' => $this->incident->addManager($username, $this->getEventTypes()),
                'subscribe' => $this->incident->addSubscriber($username, $this->getEventTypes()),
                'unmanage' => $this->incident->removeManager(),
                'unsubscribe' => $this->incident->removeSubscriber($username)
            };
        } catch (Exception $e) {
            Logger::error('Failed to request quick action "%s" for user "%s": %s', $pressedButton, $username, $e);
            Notification::error(t('Failed to submit your request'));

            return;
        }

        Notification::success(t('Your request has been submitted'));
    }

    protected function getEventTypes(): array
    {
        return array_keys(
            SourceHookLocator::forType($this->sourceType)?->getEventTypes() ?? []
        );
    }
}
