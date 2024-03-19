<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

/**
 * ObjectExtraTag database model
 *
 * @property int $object_id
 * @property string $tag
 * @property string $value
 */
class ObjectExtraTag extends Model
{
    public function getTableName()
    {
        return 'object_extra_tag';
    }

    public function getKeyName()
    {
        return ['object_id', 'tag'];
    }

    public function getColumns()
    {
        return [
            'object_id',
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
        $relations->belongsTo('object', Objects::class);
    }
}
