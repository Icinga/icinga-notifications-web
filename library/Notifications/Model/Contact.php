<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Model\Behavior\HasAddress;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

/**
 * @property int $id
 */
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
            'username',
            'default_channel_id'
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
        $relations->belongsTo('channel', Channel::class)
            ->setCandidateKey('default_channel_id');

        $relations->belongsToMany('incident', Incident::class)
            ->through('incident_contact')
            ->setJoinType('LEFT');

        $relations->hasMany('incident_contact', IncidentContact::class);
        $relations->hasMany('incident_history', IncidentHistory::class);
        $relations->hasMany('rotation_member', RotationMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('contact_address', ContactAddress::class);
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');

        $relations->belongsToMany('contactgroup', Contactgroup::class)
            ->through('contactgroup_member')
            ->setJoinType('LEFT');
    }
}
