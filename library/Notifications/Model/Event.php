<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Model\Behavior\IcingaCustomVars;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use ipl\Web\Widget\Icon;

/**
 * Event model
 *
 * @property int $id
 * @property DateTime $time
 * @property string $object_id
 * @property string $type
 * @property ?string $severity
 * @property ?string $message
 * @property ?string $username
 * @property ?bool $mute
 * @property ?string $mute_reason
 *
 * @property Query|Objects $object
 * @property Query|IncidentHistory $incident_history
 * @property Query|Incident $incident
 */
class Event extends Model
{
    public function getTableName(): string
    {
        return 'event';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'time',
            'object_id',
            'type',
            'severity',
            'message',
            'username',
            'mute',
            'mute_reason'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'time'      => t('Received On'),
            'object_id' => t('Object Id'),
            'type'      => t('Type'),
            'severity'  => t('Severity'),
            'message'   => t('Message'),
            'username'  => t('Username'),
            'mute'      => t('Mute'),
            'mute_reason' => t('Mute Reason')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['object.name'];
    }

    public function getDefaultSort(): string
    {
        return 'event.time';
    }

    public static function on(Connection $db): Query
    {
        $query = parent::on($db);

        $query->on(Query::ON_SELECT_ASSEMBLED, function (Select $select) use ($query) {
            if (isset($query->getUtilize()['event.object.object_id_tag'])) {
                Database::registerGroupBy($query, $select);
            }
        });

        return $query;
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['time']));
        $behaviors->add(new Binary(['object_id']));
        $behaviors->add(new BoolCast(['mute']));
        $behaviors->add(new IcingaCustomVars());
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('object', Objects::class)->setJoinType('LEFT');

        $relations->hasOne('incident_history', IncidentHistory::class);

        $relations
            ->belongsToOne('incident', Incident::class)
            ->through('incident_event')
            ->setJoinType('LEFT');
    }

    /**
     * Get the severity text
     *
     * @return ?string
     */
    public function getSeverityText(): ?string
    {
        return static::mapSeverity($this->severity);
    }

    public static function mapSeverity(?string $severity): ?string
    {
        return match ($severity) {
            'ok'      => t('Ok', 'notifications.severity'),
            'crit'    => t('Critical', 'notifications.severity'),
            'warning' => t('Warning', 'notifications.severity'),
            'err'     => t('Error', 'notifications.severity'),
            'debug'   => t('Debug', 'notifications.severity'),
            'info'    => t('Information', 'notifications.severity'),
            'alert'   => t('Alert', 'notifications.severity'),
            'emerg'   => t('Emergency', 'notifications.severity'),
            'notice'  => t('Notice', 'notifications.severity'),
            default   => null
        };
    }

    /**
     * Get the type text
     *
     * @return string
     */
    public function getTypeText(): string
    {
        if ($this->type === 'state') {
            if ($this->severity === 'ok') {
                return t('recovered', 'notifications.type');
            }

            return t('ran into a problem', 'notifications.type');
        }

        return match ($this->type) {
            'acknowledgement-set'     => t('has been acknowledged', 'notifications.type'),
            'acknowledgement-cleared' => t('was unacknowledged', 'notifications.type'),
            'downtime-start'          => t('entered a downtime period', 'notifications.type'),
            'downtime-end'            => t('left a downtime period', 'notifications.type'),
            'downtime-removed'        => t('prematurely left a downtime period', 'notifications.type'),
            'flapping-start'          => t('entered a flapping period', 'notifications.type'),
            'flapping-end'            => t('left a flapping period', 'notifications.type'),
            'incident-age'            => t('exceeded a time constraint', 'notifications.type'),
            'mute'                    => t('was muted', 'notifications.type'),
            'unmute'                  => t('was unmuted', 'notifications.type'),
            default                   => '' // custom
        };
    }

    /**
     * Get the icon for the event
     *
     * @return ?Icon
     */
    public function getIcon(): ?Icon
    {
        if ($this->type === 'state') {
            $severity = $this->severity;
            $class = 'severity-' . $severity;

            return match ($severity) {
                'ok'      => (new Icon(Icons::SEVERITY_OK, ['class' => $class]))->setStyle('fa-regular'),
                'crit'    => new Icon(Icons::SEVERITY_CRIT, ['class' => $class]),
                'warning' => new Icon(Icons::SEVERITY_WARN, ['class' => $class]),
                'err'     => (new Icon(Icons::SEVERITY_ERR, ['class' => $class]))->setStyle('fa-regular'),
                'debug'   => new Icon(Icons::SEVERITY_DEBUG),
                'info'    => new Icon(Icons::SEVERITY_INFO),
                'alert'   => new Icon(Icons::SEVERITY_ALERT),
                'emerg'   => new Icon(Icons::SEVERITY_EMERG),
                'notice'  => new Icon(Icons::SEVERITY_NOTICE),
                default   => null
            };
        }

        return match ($this->type) {
            'acknowledgement-set'     => new Icon(Icons::ACKNOWLEDGED),
            'acknowledgement-cleared' => new Icon(Icons::UNACKNOWLEDGED),
            'downtime-start',
            'downtime-end',
            'downtime-removed'        => new Icon(Icons::DOWNTIME),
            'flapping-start',
            'flapping-end'            => new Icon(Icons::FLAPPING),
            'incident-age'            => new Icon(Icons::INCIDENT_AGE),
            'custom'                  => new Icon(Icons::CUSTOM),
            'mute'                    => new Icon(Icons::MUTE),
            'unmute'                  => new Icon(Icons::UNMUTE),
            default                   => null
        };
    }
}
