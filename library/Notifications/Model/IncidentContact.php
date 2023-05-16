<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class IncidentContact extends Model
{
    public function getTableName()
    {
        return 'incident_contact';
    }

    public function getKeyName()
    {
        return ['incident_id', 'contact_id'];
    }

    public function getColumns()
    {
        return [
            'incident_id',
            'contact_id',
            'role'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'incident_id'   => t('Incident Id'),
            'contact_id'    => t('Contact Id'),
            'role'          => t('Role')
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('incident', Incident::class);
        $relations->belongsTo('contact', Contact::class);
    }
}
