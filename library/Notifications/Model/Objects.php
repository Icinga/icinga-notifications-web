<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
 * @property ?string $url
 * @property ?string $mute_reason
 *
 * @property Query|Event $event
 * @property Query|Incident $incident
 * @property Query|Tag $tag
 * @property Query|ObjectExtraTag $object_extra_tag
 * @property Query|ExtraTag $extra_tag
 * @property Query|Source $source
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
            'source_id',
            'name',
            'url',
            'mute_reason'
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
        return ObjectsRendererHook::getObjectName($this);
    }
}
