<?php

namespace Icinga\Module\Notifications\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Notifications\Daemon\Daemon;

class DaemonCommand extends Command {
    public function runAction(): void {
        $daemon = Daemon::get();
    }
}
