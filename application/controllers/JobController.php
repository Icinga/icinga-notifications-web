<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\JobTracker;
use Icinga\Module\Notifications\Model\JobQueue;
use Icinga\Web\Controller\ActionController;
use Icinga\Web\Session;

/**
 * Report the state of the jobs tracked by {@see JobTracker}
 *
 * Polled by quick-action.js for each quick action marked as pending, to reload it once the daemon has
 * processed the job.
 *
 * This class does not extend a module controller, because {@see static::stateAction()} must be available to users
 * without the `module/notifications` permission.
 */
class JobController extends ActionController
{
    /**
     * Report the state of the tracked job with the given id as JSON
     *
     * @return void
     */
    public function stateAction(): void
    {
        $jobId = $this->params->getRequired('id');
        $tracker = JobTracker::instance();
        $state = $tracker->getState($jobId);

        [$successMessage, $failureMessage] = match ($tracker->getAction($jobId)) {
            'subscribe' => [
                'You are now subscribed to this incident', 'Could not subscribe you to this incident'
            ],
            'unsubscribe' => [
                'You have unsubscribed from this incident', 'Could not unsubscribe you from this incident'
            ],
            'manage' => [
                'You are now managing this incident', 'Could not assign you a manager of this incident'
            ],
            'unmanage' => [
                'You are no longer managing this incident', 'Could not remove you as a manager of this incident'
            ],
        };

        if ($state !== null && JobTracker::isFinished($state)) {
            $tracker->forget($jobId);
            Session::getSession()->write();
        }

        $this->getResponse()->json()->setSuccessData([
            'finished' => $state === null || JobTracker::isFinished($state),
            'message'  => match ($state) {
                JobQueue::STATE_ERROR =>
                    $this->translate($failureMessage) .
                    ': ' .
                    $this->translate('see Icinga Notifications logs for more details')
                ,
                JobTracker::STATE_TIMED_OUT =>
                    $this->translate($failureMessage) .
                    ': ' .
                    $this->translate('make sure that Icinga Notifications is running'),
                default => null
            },
            'success' => $this->translate($successMessage) ?? null,
        ])->sendResponse();
    }
}
