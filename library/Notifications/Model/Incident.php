<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Incident
 *
 * @property int $id
 * @property string $object_id
 * @property DateTime $started_at
 * @property ?DateTime $recovered_at
 * @property string $severity
 *
 * @property Model<Objects> | Query<Objects> $object
 * @property Model<Event> | Query<Event> $event
 * @property Model<Contact> | Query<Contact> $contact
 * @property Model<IncidentContact> | Query<IncidentContact> $incident_contact
 * @property Model<IncidentHistory> | Query<IncidentContact> $incident_history
 * @property Model<Rule> | Query<Rule> $rule
 * @property Model<RuleEscalation> | Query<RuleEscalation> $rule_escalation
 */
class Incident extends Model
{
    public function getTableName(): string
    {
        return 'incident';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'object_id',
            'started_at',
            'recovered_at',
            'severity'
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getColumnDefinitions(): array
    {
        return [
            'object_id'    => t('Object Id'),
            'started_at'   => t('Started At'),
            'recovered_at' => t('Recovered At'),
            'severity'     => t('Severity')
        ];
    }

    /**
     * @return array<string>
     */
    public function getSearchColumns(): array
    {
        return ['object.name'];
    }

    /**
     * @return array<string>
     */
    public function getDefaultSort(): array
    {
        return ['incident.severity desc, incident.started_at'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new Binary(['object_id']));
        $behaviors->add(new MillisecondTimestamp([
            'started_at',
            'recovered_at'
        ]));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('object', Objects::class);

        $relations
            ->belongsToMany('event', Event::class)
            ->through('incident_event');

        $relations->belongsToMany('contact', Contact::class)
            ->through('incident_contact');

        $relations->hasMany('incident_contact', IncidentContact::class);
        $relations->hasMany('incident_history', IncidentHistory::class);

        $relations
            ->belongsToMany('rule', Rule::class)
            ->through('incident_rule');

        $relations
            ->belongsToMany('rule_escalation', RuleEscalation::class)
            ->through('incident_rule_escalation_state')
            ->setJoinType('LEFT');
    }
}
