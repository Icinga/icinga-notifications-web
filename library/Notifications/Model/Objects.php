<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Hook\ObjectsRendererHook;
use Icinga\Module\Notifications\Model\Behavior\IdTagAggregator;
use ipl\Html\ValidHtml;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Object
 *
 * @property string $id
 * @property int $source_id
 * @property string $name
 * @property string $host
 * @property ?string $service
 * @property ?string $url
 *
 * @property Query | Event $event
 * @property Query | Incident $incident
 * @property Query | Tag $tag
 * @property Query | ObjectExtraTag $object_extra_tag
 * @property Query | ExtraTag $extra_tag
 * @property Query | Source $source
 * @property array<string, string> $id_tags
 */
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
            'name',
            'url'
        ];
    }

    /**
     * @return string[]
     */
    public function getSearchColumns()
    {
        return ['object_id_tag.tag', 'object_id_tag.value'];
    }

    /**
     * @return string
     */
    public function getDefaultSort()
    {
        return 'object.name';
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['id']));
        $behaviors->add(new IdTagAggregator());
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('event', Event::class);
        $relations->hasMany('incident', Incident::class);

        $relations->hasMany('object_id_tag', ObjectIdTag::class);
        $relations->hasMany('tag', Tag::class);
        $relations->hasMany('object_extra_tag', ObjectExtraTag::class)
            ->setJoinType('LEFT');
        $relations->hasMany('extra_tag', ExtraTag::class)
            ->setJoinType('LEFT');

        $relations->belongsTo('source', Source::class)->setJoinType('LEFT');
    }

    public function getName(): ValidHtml
    {
        return ObjectsRendererHook::getObjectName($this);
    }
}
