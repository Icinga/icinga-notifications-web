<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model\Behavior;

use Icinga\Module\Notifications\Common\Auth;
use ipl\Orm\AliasedExpression;
use ipl\Orm\ColumnDefinition;
use ipl\Orm\Contract\QueryAwareBehavior;
use ipl\Orm\Contract\RewriteColumnBehavior;
use ipl\Orm\Query;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Rule;

class ObjectTags implements RewriteColumnBehavior, QueryAwareBehavior
{
    use Auth;

    protected ?Query $query = null;

    public function setQuery(Query $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function rewriteCondition(Filter\Condition $condition, $relation = null): ?Rule
    {
        /** @var string $relation */
        /** @var ?string $column */
        $column = $condition->metaData()->get('columnName');
        if ($column !== null) {
            // TODO: ipl/orm is still unable to correctly optimize such relations, hence this
            //       is the same fix as for https://github.com/Icinga/icingadb-web/issues/865
            $relation = substr($relation, 0, -4) . 'object_id_tag.';
            $condition->metaData()
                ->set('forceResolved', true)
                ->set('requiresTransformation', true)
                ->set('columnPath', $relation . $column)
                ->set('relationPath', substr($relation, 0, -1));
            $condition->setColumn('always_the_same_but_totally_irrelevant');

            return $condition;
        }

        return null;
    }

    public function rewriteColumn($column, ?string $relation = null): AliasedExpression
    {
        /** @var string $relation */
        /** @var string $column */
        $model = $this->query->getModel();
        $subQuery = $this->query->createSubQuery(new $model(), $relation)
            ->limit(1)
            ->columns('value')
            ->filter(Filter::equal('tag', $column));

        $this->applyRestrictions($subQuery);

        $alias = $this->query->getDb()->quoteIdentifier([str_replace('.', '_', $relation) . "_$column"]);

        [$select, $values] = $this->query->getDb()->getQueryBuilder()->assembleSelect($subQuery->assembleSelect());
        return new AliasedExpression($alias, "($select)", null, ...$values);
    }

    public function rewriteColumnDefinition(ColumnDefinition $def, string $relation): void
    {
        $def->setLabel(ucfirst($def->getName()));
    }

    public function isSelectableColumn(string $name): bool
    {
        return true;
    }
}
