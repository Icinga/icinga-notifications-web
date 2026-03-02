<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;

/**
 * ObjectIdTag database model
 *
 * @property string $object_id
 * @property string $tag
 * @property string $value
 */
class ObjectIdTag extends Model
{
    public function getTableName(): string
    {
        return 'object_id_tag';
    }

    public function getKeyName(): array
    {
        return ['object_id', 'tag'];
    }

    public function getColumns(): array
    {
        return [
            'object_id',
            'tag',
            'value'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new Binary(['object_id']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('object', Objects::class);
    }
}
