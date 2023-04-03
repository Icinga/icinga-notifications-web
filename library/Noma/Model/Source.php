<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Source extends Model
{
    public function getTableName()
    {
        return 'source';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'type',
            'name'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'type' => t('Type'),
            'name' => t('Name')
        ];
    }

    public function getSearchColumns()
    {
        return ['type'];
    }

    public function getDefaultSort()
    {
        return 'source.name';
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('event', Event::class);
        $relations->hasMany('source_object', SourceObject::class);
        $relations->hasMany('object_extra_tag', ObjectExtraTag::class)
            ->setJoinType('LEFT');
    }
}
