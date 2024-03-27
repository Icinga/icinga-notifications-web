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

    /**
     * @return array<string>
     */
    public function getColumns(): array
    {
        return [
            'full_name',
            'username',
            'color',
            'default_channel_id'
        ];
    }

    /**
     * @return array<string>
     */
    public function getColumnDefinitions(): array
    {
        return [
            'full_name' => t('Full Name'),
            'username'  => t('Username'),
            'color'     => t('Color')
        ];
    }

    /**
     * @return array<string>
     */
    public function getSearchColumns(): array
    {
        return ['full_name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new HasAddress());
    }

    /**
     * @return array<string>
     */
    public function getDefaultSort(): array
    {
        return ['full_name'];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('channel', Channel::class)
            ->setCandidateKey('default_channel_id');

        $relations->belongsToMany('incident', Incident::class)
            ->through('incident_contact')
            ->setJoinType('LEFT');

        $relations->hasMany('incident_contact', IncidentContact::class);
        $relations->hasMany('incident_history', IncidentHistory::class);
        $relations->hasMany('schedule_member', ScheduleMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('contact_address', ContactAddress::class);
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');
    }
}
