<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model\Behavior;

use Icinga\Module\Notifications\Model\Objects;
use ipl\Orm\AliasedExpression;
use ipl\Orm\ColumnDefinition;
use ipl\Orm\Contract\PropertyBehavior;
use ipl\Orm\Contract\QueryAwareBehavior;
use ipl\Orm\Contract\RewriteColumnBehavior;
use ipl\Orm\Exception\InvalidColumnException;
use ipl\Orm\Query;
use ipl\Sql\Adapter\Pgsql;
use ipl\Stdlib\Filter;

use function ipl\Stdlib\iterable_key_first;

class IdTagAggregator extends PropertyBehavior implements RewriteColumnBehavior, QueryAwareBehavior
{
    /** @var ?Query */
    protected ?Query $query = null;

    final public function __construct()
    {
        // TODO: Should not be based on PropertyBehavior. (https://github.com/Icinga/ipl-orm/issues/129)
        parent::__construct(['id_tags']);
    }

    /** @return $this */
    public function setQuery(Query $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function rewriteColumn($column, ?string $relation = null)
    {
        if ($column === 'id_tags') {
            $path = ($relation ?? $this->query->getModel()->getTableAlias()) . '.object_id_tag';

            $this->query->utilize($path);

            $pathRelation = $this->query->getResolver()->resolveRelation($path);
            if ($relation !== null) {
                // TODO: This is really another case where ipl-orm could automatically adjust the join type...
                $pathRelation->setJoinType($this->query->getResolver()->resolveRelation($relation)->getJoinType());
            }

            $pathAlias = $this->query->getResolver()->getAlias($pathRelation->getTarget());
            $myAlias = $this->query->getResolver()->getAlias(
                $relation
                    ? $this->query->getResolver()->resolveRelation($relation)->getTarget()
                    : $this->query->getModel()
            );

            return new AliasedExpression("{$myAlias}_id_tags", sprintf(
                $this->query->getDb()->getAdapter() instanceof Pgsql
                    ? 'json_object_agg(COALESCE(%s, \'\'), %s)'
                    : 'json_objectagg(COALESCE(%s, \'\'), %s)',
                $this->query->getResolver()->qualifyColumn('tag', $pathAlias),
                $this->query->getResolver()->qualifyColumn('value', $pathAlias)
            ));
        }
    }

    public function isSelectableColumn(string $name): bool
    {
        return $name === 'id_tags';
    }

    /** @return array<string, string> */
    public function fromDb($value, $key, $context): array
    {
        if (! is_string($value)) {
            return [];
        }

        $tags = json_decode($value, true) ?? [];
        if (iterable_key_first($tags) === '') {
            return [];
        }

        return $tags;
    }

    /**
     * @return never
     *
     * @throws InvalidColumnException
     */
    public function toDb($value, $key, $context): never
    {
        throw new InvalidColumnException($key, new Objects());
    }

    public function rewriteColumnDefinition(ColumnDefinition $def, string $relation): void
    {
    }

    public function rewriteCondition(Filter\Condition $condition, $relation = null)
    {
    }
}
