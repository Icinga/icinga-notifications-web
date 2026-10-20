<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\Web\Widget\Icon;

/**
 * Incident severity levels
 *
 * Each case maps to the backing string stored in the `severity` type column of the `incident` and `incident_history`
 * tables. Declaration order reflects increasing acuity. Register {@see \ipl\Orm\Behavior\EnumCast} on a model to have
 * those columns hydrated automatically as enum instances.
 */
enum Severity: string
{
    case OK        = 'ok';
    case WARNING   = 'warning';
    case ERROR     = 'error';
    case CRITICAL  = 'critical';

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
            self::ERROR     => t('Error', 'notifications.severity')
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
            self::ERROR     => 'circle-xmark'
        };

        return new Icon($icon, [
            'class' => ['severity-' . $this->getValue()],
            'title' => sprintf(t('Severity set to %s'), $this->getLabel())
        ]);
    }
}
