<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook;

use Icinga\Application\Hook\HookEssentials;
use ipl\Html\ValidHtml;

/**
 * Base class for source integration hooks for the initial setup
 */
abstract class SourceIntegrationSetupHook
{
    use HookEssentials;

    protected bool $finished = false;

    protected static function getHookName(): string
    {
        return 'Notifications\\SourceIntegrationSetup';
    }

    /**
     * Get the integration
     *
     * @return ValidHtml
     */
    abstract public function getIntegration(): ValidHtml;

    /**
     * Set the finished flag
     *
     * Once the integration is successfully added, this flag must be set to true to reload page on success.
     *
     * @param bool $finished
     *
     * @return void
     */
    protected function setFinished(bool $finished = true): void
    {
        $this->finished = $finished;
    }

    /**
     * Whether the integration is finished successfully
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }
}
