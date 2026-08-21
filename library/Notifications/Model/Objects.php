<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Common\Collection;
use Icinga\Module\Notifications\Common\Model;
use Icinga\Module\Notifications\Model\Behavior\IdTagAggregator;
use Icinga\Module\Notifications\Model\Behavior\SourceAggregator;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * Object
 *
 * @property string $id
 * @property string $name
 * @property ?string $url
 * @property Source[] $sources
 *
 * @property Query<Incident>|Collection<Incident> $incident
 * @property Query<ObjectIdTag>|Collection<ObjectIdTag> $object_id_tag
 * @property Query<Tag>|Collection<Tag> $tag
 * @property Query<Source>|Collection<Source> $source
 * @property array<string, string> $id_tags
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
            'name',
            'url'
        ];
    }

    /**
     * @return string[]
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
        $behaviors->add(new IdTagAggregator());
        $behaviors->add(new SourceAggregator());
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('incident', Incident::class)
            ->setJoinType('LEFT');

        $relations->hasMany('object_id_tag', ObjectIdTag::class);
        $relations->hasMany('tag', Tag::class);

        $relations->belongsToMany('source', Source::class)
            ->through('object_source')
            ->setJoinType('LEFT');
    }
}
