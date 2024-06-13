<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Notifications\Daemon\Daemon;

class DaemonCommand extends Command
{
    /**
     * Run the notifications daemon
     *
     * This program allows clients to subscribe to notifications and receive them in real-time on the desktop.
     *
     * USAGE:
     *
     *     icingacli notifications daemon run [OPTIONS]
     *
     * OPTIONS
     *
     * --verbose   Enable verbose output
     * --debug     Enable debug output
     */
    public function runAction(): void
    {
        Daemon::get();
    }
}
