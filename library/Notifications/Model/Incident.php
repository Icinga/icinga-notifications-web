<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Behavior\IcingaCustomVars;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Sql\Select;

/**
 * Incident Model
 *
 * @property int $id
 * @property string $object_id
 * @property DateTime $started_at
 * @property ?DateTime $recovered_at
 * @property string $severity
 *
 * @property Query|Objects $object
 * @property Query|Event $event
 * @property Query|Contact $contact
 * @property Query|IncidentContact $incident_contact
 * @property Query|IncidentHistory $incident_history
 * @property Query|Rule $rule
 * @property Query|RuleEscalation $rule_escalation
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

    public function getColumnDefinitions(): array
    {
        return [
            'object_id'    => t('Object Id'),
            'started_at'   => t('Started At'),
            'recovered_at' => t('Recovered At'),
            'severity'     => t('Severity')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['object.name'];
    }

    public function getDefaultSort(): array
    {
        return ['incident.severity desc, incident.started_at'];
    }

    public static function on(Connection $db): Query
    {
        $query = parent::on($db);

        $query->on(Query::ON_SELECT_ASSEMBLED, function (Select $select) use ($query) {
            if (isset($query->getUtilize()['incident.object.object_id_tag'])) {
                Database::registerGroupBy($query, $select);
            }
        });

        return $query;
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new Binary(['object_id']));
        $behaviors->add(new MillisecondTimestamp([
            'started_at',
            'recovered_at'
        ]));
        $behaviors->add(new IcingaCustomVars());
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
