<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class SourceObject extends Model
{
    public function getTableName()
    {
        return 'source_object';
    }

    public function getKeyName()
    {
        return ['source_id', 'object_id'];
    }

    public function getColumns()
    {
        return [
            'source_id',
            'object_id',
            'name',
            'url'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'source_id' => t('Source Id'),
            'object_id' => t('Object Id'),
            'name'      => t('Name'),
            'url'       => t('Url')
        ];
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['object_id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('source', Source::class);
        $relations->belongsTo('object', Objects::class);
    }
}
