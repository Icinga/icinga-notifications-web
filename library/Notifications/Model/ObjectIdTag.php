<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Behavior\Binary;
use ipl\Orm\Behaviors;
use ipl\Orm\Contract\RewriteFilterBehavior;
use ipl\Orm\Relations;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;

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
        $behaviors->add(new class implements RewriteFilterBehavior {
            public function rewriteCondition(Condition $condition, $relation = null): ?Filter\Chain
            {
                if ($condition->metaData()->has('requiresTransformation')) {
                    /** @var string $columnName */
                    $columnName = $condition->metaData()->get('columnName');
                    $nameFilter = Filter::like($relation . 'tag', $columnName);
                    $class = get_class($condition);
                    $valueFilter = new $class($relation . 'value', $condition->getValue());

                    return Filter::all($nameFilter, $valueFilter);
                }

                return null;
            }
        });
    }

    public function createRelations(Relations $relations): void
    {
        $relations->belongsTo('object', Objects::class);
    }
}
