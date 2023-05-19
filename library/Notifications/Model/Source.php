<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Web\Widget\IcingaIcon;
use ipl\Web\Widget\Icon;

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

    /**
     * Get the source icon
     *
     * @return Icon
     */
    public function getIcon(): Icon
    {
        $icon = null;
        switch ($this->type) {
            //TODO(sd): Add icons for other known sources
            case 'icinga2':
                $icon = new IcingaIcon('icinga');
                break;
            default:
                $icon = new Icon('share-nodes');
        }

        return $icon;
    }
}
