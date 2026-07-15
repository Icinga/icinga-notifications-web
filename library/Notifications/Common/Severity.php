<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\Web\Widget\Icon;

/**
 * Incident severity levels
 *
 * Each case maps to the backing string stored in the `severity` type column of the `incident` and `incident_history`
 * tables. Register {@see \ipl\Orm\Behavior\EnumCast} on a model to have those columns hydrated automatically as enum
 * instances.
 */
enum Severity: string
{
    case OK        = 'ok';
    case CRITICAL  = 'crit';
    case WARNING   = 'warning';
    case ERROR     = 'err';
    case DEBUG     = 'debug';
    case INFO      = 'info';
    case ALERT     = 'alert';
    case EMERGENCY = 'emerg';
    case NOTICE    = 'notice';

    /**
     * Get the backing string value
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::OK        => t('Ok', 'notifications.severity'),
            self::CRITICAL  => t('Critical', 'notifications.severity'),
            self::WARNING   => t('Warning', 'notifications.severity'),
            self::ERROR     => t('Error', 'notifications.severity'),
            self::DEBUG     => t('Debug', 'notifications.severity'),
            self::INFO      => t('Information', 'notifications.severity'),
            self::ALERT     => t('Alert', 'notifications.severity'),
            self::EMERGENCY => t('Emergency', 'notifications.severity'),
            self::NOTICE    => t('Notice', 'notifications.severity'),
        };
    }

    /**
     * Get the icon
     *
     * @return Icon
     */
    public function getIcon(): Icon
    {
        $icon = match ($this) {
            self::OK        => 'circle-check',
            self::CRITICAL  => 'circle-exclamation',
            self::WARNING   => 'triangle-exclamation',
            self::ERROR     => 'circle-xmark',
            self::DEBUG     => 'bug-slash',
            self::INFO      => 'circle-info',
            self::ALERT     => 'bell',
            self::EMERGENCY => 'bullhorn',
            self::NOTICE    => 'envelope'
        };

        return new Icon($icon, [
            'class' => ['severity-' . $this->getValue()],
            'title' => sprintf(t('Severity set to %s'), $this->getLabel())
        ]);
    }
}
