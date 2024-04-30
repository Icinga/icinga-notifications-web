<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Icons;
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
 * @property Query | Objects $object
 * @property Query | IncidentHistory $incident_history
 * @property Query | Incident $incident
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
        switch ($severity) {
            case 'ok':
                $label = t('Ok', 'noma.severity');
                break;
            case 'crit':
                $label = t('Critical', 'noma.severity');
                break;
            case 'warning':
                $label = t('Warning', 'noma.severity');
                break;
            case 'err':
                $label = t('Error', 'noma.severity');
                break;
            case 'debug':
                $label = t('Debug', 'noma.severity');
                break;
            case 'info':
                $label = t('Information', 'noma.severity');
                break;
            case 'alert':
                $label = t('Alert', 'noma.severity');
                break;
            case 'emerg':
                $label = t('Emergency', 'noma.severity');
                break;
            case 'notice':
                $label = t('Notice', 'noma.severity');
                break;
            default:
                $label = null;
        }

        return $label;
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

        switch ($this->type) {
            case 'acknowledgement-set':
                return t('has been acknowledged', 'notifications.type');
            case 'acknowledgement-cleared':
                return t('was unacknowledged', 'notifications.type');
            case 'downtime-start':
                return t('entered a downtime period', 'notifications.type');
            case 'downtime-end':
                return t('left a downtime period', 'notifications.type');
            case 'downtime-removed':
                return t('prematurely left a downtime period', 'notifications.type');
            case 'flapping-start':
                return t('entered a flapping period', 'notifications.type');
            case 'flapping-end':
                return t('left a flapping period', 'notifications.type');
            case 'incident-age':
                return t('exceeded a time constraint', 'notifications.type');
            case 'mute':
                return t('was muted', 'notifications.type');
            case 'unmute':
                return t('was unmuted', 'notifications.type');
            default: // custom
                return '';
        }
    }

    /**
     * Get the icon for the event
     *
     * @return ?Icon
     */
    public function getIcon(): ?Icon
    {
        $icon = null;

        if ($this->type === 'state') {
            $severity = $this->severity;
            $class = 'severity-' . $severity;
            switch ($severity) {
                case 'ok':
                    $icon = (new Icon(Icons::SEVERITY_OK, ['class' => $class]))->setStyle('fa-regular');
                    break;
                case 'crit':
                    $icon = new Icon(Icons::SEVERITY_CRIT, ['class' => $class]);
                    break;
                case 'warning':
                    $icon = new Icon(Icons::SEVERITY_WARN, ['class' => $class]);
                    break;
                case 'err':
                    $icon = (new Icon(Icons::SEVERITY_ERR, ['class' => $class]))->setStyle('fa-regular');
                    break;
                case 'debug':
                    $icon = new Icon(Icons::SEVERITY_DEBUG);
                    break;
                case 'info':
                    $icon = new Icon(Icons::SEVERITY_INFO);
                    break;
                case 'alert':
                    $icon = new Icon(Icons::SEVERITY_ALERT);
                    break;
                case 'emerg':
                    $icon = new Icon(Icons::SEVERITY_EMERG);
                    break;
                case 'notice':
                    $icon = new Icon(Icons::SEVERITY_NOTICE);
                    break;
            }

            return $icon;
        }

        switch ($this->type) {
            case 'acknowledgement-set':
                $icon = new Icon(Icons::ACKNOWLEDGED);
                break;
            case 'acknowledgement-cleared':
                $icon = new Icon(Icons::UNACKNOWLEDGED);
                break;
            case 'downtime-start':
            case 'downtime-end':
            case 'downtime-removed':
                $icon = new Icon(Icons::DOWNTIME);
                break;
            case 'flapping-start':
            case 'flapping-end':
                $icon = new Icon(Icons::FLAPPING);
                break;
            case 'incident-age':
                $icon = new Icon(Icons::INCIDENT_AGE);
                break;
            case 'custom':
                $icon = new Icon(Icons::CUSTOM);
                break;
            case 'mute':
                $icon = new Icon(Icons::MUTE);
                break;
            case 'unmute':
                $icon = new Icon(Icons::UNMUTE);
                break;
        }

        return $icon;
    }
}
