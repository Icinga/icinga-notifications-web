<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $incident_id
 * @property ?int $contact_id
 * @property string $role
 *
 * @property Query|Incident $incident
 * @property Query|Contact $contact
 */
class IncidentContact extends Model
{
    public function getTableName(): string
    {
        return 'incident_contact';
    }

    public function getKeyName(): array
    {
        return ['incident_id', 'contact_id'];
    }

    public function getColumns(): array
    {
        return [
            'incident_id',
            'contact_id',
            'role'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'incident_id'   => t('Incident Id'),
            'contact_id'    => t('Contact Id'),
            'role'          => t('Role')
        ];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('incident', Incident::class);
        $relations->belongsTo('contact', Contact::class);
    }
}
