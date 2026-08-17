<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

/**
 * Incident history entry types
 *
 * Each case maps to the backing string stored in the `type` column of the `incident_history` table.
 * Register {@see \ipl\Orm\Behavior\EnumCast} on a model to have those columns hydrated automatically as enum instances.
 */
enum IncidentHistoryType: string
{
    case INCIDENT_SEVERITY_CHANGED = 'incident_severity_changed';
    case ESCALATION_TRIGGERED = 'escalation_triggered';
    case CLOSED = 'closed';
    case OPENED = 'opened';
    case MUTED = 'muted';
    case UNMUTED = 'unmuted';
    case RECIPIENT_ROLE_CHANGED = 'recipient_role_changed';
    case NOTIFIED = 'notified';
    case RULE_MATCHED = 'rule_matched';

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
            self::CLOSED                        => t('Incident closed'),
            self::RECIPIENT_ROLE_CHANGED        => t('Recipient role changed'),
            self::NOTIFIED                      => t('Notified'),
            self::RULE_MATCHED                  => t('Rule matched')
        };
    }
}
