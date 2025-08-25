<?php

$webPath = getenv('ICINGAWEB_PATH');
if ($webPath === false) {
    echo "ICINGAWEB_PATH environment variable not set\n";
    exit(1);
}

$configPath = getenv('ICINGAWEB_CONFIGDIR');
if (! $configPath) {
    $configPath = realpath(__DIR__ . '/../config');
}

system('cp -R ' . $configPath . ' /tmp/config'); // copy
putenv('ICINGAWEB_CONFIGDIR=/tmp/config');
system($webPath . '/bin/icingacli module enable notifications');

$pid = pcntl_fork();
if ($pid == -1) {
    echo "Could not fork\n";
    exit(2);
}

if ($pid) {
    register_shutdown_function(function () use ($pid) {
        posix_kill($pid, SIGTERM);
    });

    require_once $webPath . '/test/php/bootstrap.php';
} else {
    pcntl_exec($webPath . '/bin/icingacli', ['web', 'serve']);
}
