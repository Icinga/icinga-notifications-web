<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Html\HtmlString;
use ipl\Html\ValidHtml;
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
            'name',
            'url'
        ];
    }

    public function getSearchColumns()
    {
        return ['object_id_tag.tag', 'object_id_tag.value'];
    }

    public function getDefaultSort()
    {
        return 'object.name';
    }

    public function createBehaviors(Behaviors $behaviors)
    {
        $behaviors->add(new Binary(['id']));
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
        //TODO: Once hooks are available, they should render the tags accordingly
        $objectTags = [];
        foreach ($this->object_id_tag as $id_tag) {
            $objectTags[] = sprintf('%s=%s', $id_tag->tag, $id_tag->value);
        }

        return new HtmlString(implode(', ', $objectTags));
    }
}
