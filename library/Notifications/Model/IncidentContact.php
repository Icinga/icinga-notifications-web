<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $incident_id
 * @property ?int $contact_id
 * @property ?int $contactgroup_id
 * @property ?int $schedule_id
 * @property string $role
 * @property DateTime $changed_at
 *
 * @property Query|Incident $incident
 * @property Query|Contact $contact
 * @property Query|Contactgroup $contactgroup
 * @property Query|Schedule $schedule
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
            'contactgroup_id',
            'schedule_id',
            'role',
            'changed_at',
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'incident_id'     => t('Incident Id'),
            'contact_id'      => t('Contact Id'),
            'contactgroup_id' => t('Contact Group Id'),
            'schedule_id'     => t('Schedule Id'),
            'role'            => t('Role'),
            'changed_at'      => t('Changed At'),
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('incident', Incident::class);
        $relations->belongsTo('contact', Contact::class)
            ->setJoinType('LEFT');
        $relations->belongsTo('contactgroup', Contactgroup::class)
            ->setJoinType('LEFT');
        $relations->belongsTo('schedule', Schedule::class)
            ->setJoinType('LEFT');
    }
}
