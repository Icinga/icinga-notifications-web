<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Event model
 *
 * @property Query|Incident $incident
 */
class Event extends Model
{
    public function getTableName()
    {
        return 'event';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'time',
            'object_id',
            'type',
            'severity',
            'message',
            'username',
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'time'      => t('Received On'),
            'object_id' => t('Object Id'),
            'type'      => t('Type'),
            'severity'  => t('Severity'),
            'message'   => t('Message'),
            'username'  => t('Username')
        ];
    }

    public function getSearchColumns()
    {
        return ['object.name'];
    }

    public function getDefaultSort()
    {
        return 'event.time';
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new MillisecondTimestamp(['time']));
        $behaviors->add(new Binary(['object_id']));
    }

    public function createRelations(Relations $relations)
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
}
