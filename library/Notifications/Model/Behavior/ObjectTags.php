<?php

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

    /** @var ?Query */
    protected ?Query $query = null;

    public function setQuery(Query $query): self
    {
        $this->query = $query;

        return $this;
    }

    public function rewriteCondition(Filter\Condition $condition, $relation = null): ?Rule
    {
        $filterAll = null;
        /** @var string $relation */
        /** @var ?string $column */
        $column = $condition->metaData()->get('columnName');
        if ($column !== null) {
            if (str_ends_with($relation, 'extra_tag.')) {
                $relation = substr($relation, 0, -10) . 'object_extra_tag.';
            } else { // tag.
                $relation = substr($relation, 0, -4) . 'object_id_tag.';
            }

            $nameFilter = Filter::like($relation . 'tag', $column);
            $class = get_class($condition);
            $valueFilter = new $class($relation . 'value', $condition->getValue());

            $filterAll = Filter::all($nameFilter, $valueFilter);
        }

        return $filterAll;
    }

    public function rewriteColumn($column, $relation = null): AliasedExpression
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
