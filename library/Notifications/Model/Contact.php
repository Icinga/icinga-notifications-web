<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Model\Behavior\HasAddress;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $id
 * @property string $full_name
 * @property ?string $username
 * @property string $color
 * @property int $default_channel_id
 *
 * @property Channel | Query $channel
 * @property ContactAddress | Query $contact_address
 * @property Incident | Query $incident
 * @property IncidentContact | Query $incident_contact
 * @property IncidentHistory | Query $incident_history
 * @property RuleEscalationRecipient | Query $rule_escalation_recipient
 * @property ScheduleMember | Query $schedule_member
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
            'color',
            'default_channel_id'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'full_name' => t('Full Name'),
            'username'  => t('Username'),
            'color'     => t('Color')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['full_name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new HasAddress());
    }

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
