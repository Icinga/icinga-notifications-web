<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model\Behavior;

use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use ipl\Orm\AliasedExpression;
use ipl\Orm\ColumnDefinition;
use ipl\Orm\Contract\PropertyBehavior;
use ipl\Orm\Contract\QueryAwareBehavior;
use ipl\Orm\Contract\RewriteColumnBehavior;
use ipl\Orm\Exception\InvalidColumnException;
use ipl\Orm\Query;
use ipl\Sql\Adapter\Pgsql;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;

/**
 * Aggregate all sources an object is linked to into the column `sources`
 */
class SourceAggregator extends PropertyBehavior implements RewriteColumnBehavior, QueryAwareBehavior
{
    protected ?Query $query = null;

    final public function __construct()
    {
        parent::__construct(['sources']);
    }

    /** @return Source[] */
    public function fromDb($value, $key, $context): array
    {
        $sources = [];
        foreach (json_decode($value ?? '', true) ?? [] as $row) {
            $sources[] = (new Source())->setProperties($row);
        }

        return $sources;
    }

    public function toDb($value, $key, $context): never
    {
        throw new InvalidColumnException($key, new Objects());
    }

    public function setQuery(Query $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function rewriteColumn($column, ?string $relation = null): ?AliasedExpression
    {
        if ($column === 'sources') {
            $myAlias = $this->query->getResolver()->getAlias(
                $relation !== null
                    ? $this->query->getResolver()->resolveRelation($relation)->getTarget()
                    : $this->query->getModel()
            );

            $target = new Source();
            $subQuery = $this->query
                ->createSubQuery($target, ($relation ?? $this->query->getModel()->getTableAlias()) . '.source')
                ->disableDefaultSort();

            $subResolver = $subQuery->getResolver();
            $alias = $subResolver->getAlias($target);
            $isPgsql = $this->query->getDb()->getAdapter() instanceof Pgsql;

            $row = sprintf(
                $isPgsql
                    ? "json_build_object('id', %s, 'type', %s, 'name', %s)"
                    : "json_object('id', %s, 'type', %s, 'name', %s)",
                $subResolver->qualifyColumn('id', $alias),
                $subResolver->qualifyColumn('type', $alias),
                $subResolver->qualifyColumn('name', $alias)
            );

            $subQuery->columns([new Expression(($isPgsql ? 'json_agg' : 'json_arrayagg') . "($row)")]);

            [$select, $values] = $this->query->getDb()
                ->getQueryBuilder()
                ->assembleSelect($subQuery->assembleSelect());

            return new AliasedExpression($myAlias . '_sources', $select, null, ...$values);
        }

        return null;
    }

    public function isSelectableColumn(string $name): bool
    {
        return $name === 'sources';
    }

    public function rewriteColumnDefinition(ColumnDefinition $def, string $relation): void
    {
    }

    public function rewriteCondition(Filter\Condition $condition, $relation = null): null
    {
        return null;
    }
}
