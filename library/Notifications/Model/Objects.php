<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

class Objects extends Model
{
    public function getTableName()
    {
        return 'object';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'source_id',
            'host',
            'service',
            'name',
            'url'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'host'    => t('Host Name'),
            'service' => t('Service Name')
        ];
    }

    public function getSearchColumns()
    {
        return ['host', 'service'];
    }

    public function getDefaultSort()
    {
        return 'object.host';
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['id']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('event', Event::class);
        $relations->hasMany('incident', Incident::class);
        $relations->belongsTo('source', Source::class);
        $relations->hasMany('object_extra_tag', ObjectExtraTag::class)
            ->setJoinType('LEFT');
    }
}
