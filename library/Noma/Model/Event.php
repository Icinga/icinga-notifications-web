<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

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
            'source_id',
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
            'source_id' => t('Source Id'),
            'object_id' => t('Object Id'),
            'type'      => t('Type'),
            'severity'  => t('Severity'),
            'message'   => t('Message'),
            'username'  => t('Username')
        ];
    }

    public function getSearchColumns()
    {
        return ['time'];
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
        $relations->belongsTo('source', Source::class);
        $relations->belongsTo('object', Objects::class);

        $relations
            ->belongsToOne('incident', Incident::class)
            ->through('incident_event')
            ->setJoinType('LEFT');

        $relations
            ->belongsTo('source_object', SourceObject::class)
            ->setCandidateKey(['source_id', 'object_id']);
    }

    /**
     * Get the severity text
     *
     * @return ?string
     */
    public function getSeverityText(): ?string
    {
        switch ($this->severity) {
            case 'ok':
                $severity = t('Ok');
                break;
            case 'crit':
                $severity = t('Critical');
                break;
            case 'warning':
                $severity = t('Warning');
                break;
            case 'err':
                $severity = t('Error');
                break;
            case 'debug':
                $severity = t('Debug');
                break;
            case 'info':
                $severity = t('Information');
                break;
            case 'alert':
                $severity = t('Alert');
                break;
            case 'emerg':
                $severity = t('Emergency');
                break;
            case 'notice':
                $severity = t('Notice');
                break;
        }

        return $severity ?? null;
    }
}
