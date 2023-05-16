<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class ObjectExtraTag extends Model
{
    public function getTableName()
    {
        return 'object_extra_tag';
    }

    public function getKeyName()
    {
        return ['object_id', 'source_id', 'tag'];
    }

    public function getColumns()
    {
        return [
            'object_id',
            'source_id',
            'tag',
            'value'
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
