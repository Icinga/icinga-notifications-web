<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use Icinga\Module\Noma\Model\Behavior\HasAddress;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class Contact extends Model
{
    public function getTableName(): string
    {
        return 'contact';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'full_name',
            'username'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'full_name' => t('Full Name'),
            'username'  => t('Username')
        ];
    }

    public function getSearchColumns()
    {
        return ['full_name'];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new HasAddress());
    }

    public function getDefaultSort()
    {
        return ['full_name'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('contact_address', ContactAddress::class)->setJoinType('LEFT');

        $relations->belongsToMany('incident', Incident::class)
            ->through('incident_contact')
            ->setJoinType('LEFT');

        $relations->hasMany('incident_contact', IncidentContact::class);
    }
}
