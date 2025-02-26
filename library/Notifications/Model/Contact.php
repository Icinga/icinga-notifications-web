<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property int $id
 * @property string $full_name
 * @property ?string $username
 * @property int $default_channel_id
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Channel $channel
 * @property Query|Incident $incident
 * @property Query|IncidentContact $incident_contact
 * @property Query|IncidentHistory $incident_history
 * @property Query|RotationMember $rotation_member
 * @property Query|ContactAddress $contact_address
 * @property Query|RuleEscalationRecipient $rule_escalation_recipient
 * @property Query|ContactgroupMember $contactgroup_member
 * @property Query|Contactgroup $contactgroup
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
            'default_channel_id',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'full_name'     => t('Full Name'),
            'username'      => t('Username'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['full_name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
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
        $relations->hasMany('rotation_member', RotationMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('contact_address', ContactAddress::class);
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');

        $relations->hasMany('contactgroup_member', ContactgroupMember::class);

        $relations->belongsToMany('contactgroup', Contactgroup::class)
            ->through('contactgroup_member')
            ->setJoinType('LEFT');
    }
}
