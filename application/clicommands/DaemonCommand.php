<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Notifications\Daemon\Daemon;

class DaemonCommand extends Command
{
    public function runAction(): void
    {
        Daemon::get();
    }
}
