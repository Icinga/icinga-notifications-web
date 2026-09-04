<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behavior\UUID;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\UuidInterface;

/**
 * @property int $id
 * @property string $full_name
 * @property ?string $username
 * @property int $default_channel_id
 * @property DateTime $changed_at
 * @property bool $deleted
 * @property ?UuidInterface $external_uuid
 *
 * @property Query<Channel>|Channel $channel
 * @property Query<Incident>|Collection<Incident> $incident
 * @property Query<Rotation>|Collection<Rotation> $rotation
 * @property Query<RuleEscalation>|Collection<RuleEscalation> $rule_escalation
 * @property Query<IncidentContact>|Collection<IncidentContact> $incident_contact
 * @property Query<IncidentHistory>|Collection<IncidentHistory> $incident_history
 * @property Query<RotationMember>|Collection<RotationMember> $rotation_member
 * @property Query<ContactAddress>|Collection<ContactAddress> $contact_address
 * @property Query<RuleEscalationRecipient>|Collection<RuleEscalationRecipient> $rule_escalation_recipient
 * @property Query<ContactgroupMember>|Collection<ContactgroupMember> $contactgroup_member
 * @property Query<Contactgroup>|Collection<Contactgroup> $contactgroup
 * @property Query<NotificationHistory>|Collection<NotificationHistory> $notification_history
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
            'deleted',
            'external_uuid'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'full_name'     => t('Full Name'),
            'username'      => t('Username'),
            'changed_at'    => t('Changed At'),
            'external_uuid' => t('UUID')
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
        $behaviors->add(new UUID(['external_uuid']));
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
            ->through(IncidentContact::class)
            ->setJoinType('LEFT');
        $relations->belongsToMany('rotation', Rotation::class)
            ->through(RotationMember::class)
            ->setJoinType('LEFT');
        $relations->belongsToMany('rule_escalation', RuleEscalation::class)
            ->through(RuleEscalationRecipient::class)
            ->setJoinType('LEFT');

        $relations->hasMany('incident_contact', IncidentContact::class)
            ->setJoinType('LEFT');
        $relations->hasMany('incident_history', IncidentHistory::class)
            ->setJoinType('LEFT');
        $relations->hasMany('rotation_member', RotationMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('contact_address', ContactAddress::class);
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)
            ->setJoinType('LEFT');

        $relations->hasMany('contactgroup_member', ContactgroupMember::class)
            ->setJoinType('LEFT');

        $relations->belongsToMany('contactgroup', Contactgroup::class)
            ->through(ContactgroupMember::class)
            ->setJoinType('LEFT');
        $relations->hasMany('notification_history', NotificationHistory::class)
            ->setJoinType('LEFT');
    }

    public function createVisibilityFilter(Filter\Chain $filter): void
    {
        $filter->add(Filter::equal('deleted', 'n'));
    }
}
