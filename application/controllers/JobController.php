<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Integrations\JobTracker;
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

        if ($state !== null && JobTracker::isFinished($state)) {
            $tracker->forget($jobId);
            Session::getSession()->write();
        }

        $this->getResponse()->json()->setSuccessData([
            'finished' => $state === null || JobTracker::isFinished($state),
            'message'  => match ($state) {
                JobQueue::STATE_ERROR => $this->translate('Your request could not be processed'),
                JobTracker::STATE_TIMED_OUT => $this->translate(
                    'Your request timed out, the notifications daemon might not be running'
                ),
                default => null
            }
        ])->sendResponse();
    }
}
