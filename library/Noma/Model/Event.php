<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use Icinga\Module\Noma\Model\Behavior\Timestamp;
use ipl\Orm\Behavior\Binary;
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
        $behaviors->add(new Timestamp(['time']));
        $behaviors->add(new Binary(['object_id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('source', Source::class);
        $relations->belongsTo('object', Objects::class);

        $relations
            ->belongsToMany('incident', Incident::class)
            ->through('incident_event')
            ->setIsOne()
            ->setJoinType('LEFT');

        $relations
            ->belongsTo('source_object', SourceObject::class)
            ->setCandidateKey(['source_id', 'object_id']);
    }
}
