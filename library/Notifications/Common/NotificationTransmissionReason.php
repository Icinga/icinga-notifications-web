<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

/**
 * Notification transmission reason
 *
 * Each case maps to the backing string stored as the reason a notification was triggered.
 * Register {@see \ipl\Orm\Behavior\EnumCast} on a model to have those columns hydrated automatically as enum instances.
 */
enum NotificationTransmissionReason: string
{
    case OPENED = 'opened';
    case INCIDENT_SEVERITY_CHANGED = 'incident_severity_changed';
    case ESCALATION_TRIGGERED = 'escalation_triggered';
    case MUTED = 'muted';
    case UNMUTED = 'unmuted';
    case CLOSED = 'closed';

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
     * Get the translated label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::OPENED                        => t('Incident opened'),
            self::INCIDENT_SEVERITY_CHANGED     => t('Incident severity changed'),
            self::ESCALATION_TRIGGERED          => t('Escalation triggered'),
            self::MUTED                         => t('Incident muted'),
            self::UNMUTED                       => t('Incident unmuted'),
            self::CLOSED                        => t('Incident closed')
        };
    }
}
