<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Html\HtmlString;
use ipl\Html\ValidHtml;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Object(s)
 *
 * @property  string $id
 * @property int $source_id
 * @property string $name
 * @property string $host
 * @property ?string $service
 * @property ?string $url
 *
 * @property  Model<Event> | Query<Event> $event
 * @property  Model<Incident> | Query<Incident> $incident
 * @property  Model<ObjectIdTag> | Query<ObjectIdTag> $object_id_tag
 * @property  Model<Tag> | Query<Tag> $tag
 * @property  Model<ObjectExtraTag> | Query<ObjectExtraTag> $object_extra_tag
 * @property  Model<ExtraTag> | Query<ExtraTag> $extra_tag
 * @property  Model<Source> | Query<Source> $source
 */
class Objects extends Model
{
    public function getTableName(): string
    {
        return 'object';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'source_id',
            'name',
            'url'
        ];
    }

    /**
     * @return array<string>
     */
    public function getSearchColumns(): array
    {
        return ['object_id_tag.tag', 'object_id_tag.value'];
    }

    /**
     * @return string
     */
    public function getDefaultSort(): string
    {
        return 'object.name';
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new Binary(['id']));
    }

    public function createRelations(Relations $relations): void
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
        /** @var Query $objectIdTagQuery */
        $objectIdTagQuery = $this->object_id_tag;
        /** @var ObjectIdTag $id_tag */
        foreach ($objectIdTagQuery as $id_tag) {
            /** @var string $tag */
            $tag = $id_tag->tag;
            /** @var string $value */
            $value = $id_tag->value;

            $objectTags[] = sprintf('%s=%s', $tag, $value);
        }

        return new HtmlString(implode(', ', $objectTags));
    }
}
