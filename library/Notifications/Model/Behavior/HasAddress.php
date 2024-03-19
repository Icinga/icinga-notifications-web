<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Model\Behavior;

use Icinga\Module\Notifications\Model\ContactAddress;
use ipl\Orm\AliasedExpression;
use ipl\Orm\ColumnDefinition;
use ipl\Orm\Query;
use ipl\Sql\Expression;
use ipl\Sql\Filter\Exists;
use ipl\Sql\Filter\NotExists;
use ipl\Stdlib\Filter;
use ipl\Orm\Contract\RewriteColumnBehavior;
use ipl\Orm\Contract\QueryAwareBehavior;

class HasAddress implements RewriteColumnBehavior, QueryAwareBehavior
{
    /** @var Query */
    protected $query;

    public function setQuery(Query $query)
    {
        $this->query = $query;

        return $this;
    }

    public function rewriteColumn($column, ?string $relation = null)
    {
        if ($this->isSelectableColumn($column)) {
            $type = 'email';

            $subQueryRelation = $relation !== null ? $relation . '.contact.contact_address' : 'contact.contact_address';

            $subQuery = $this->query->createSubQuery(new ContactAddress(), $subQueryRelation)
                ->limit(1)
                ->columns([new Expression('1')])
                ->filter(Filter::equal('type', $type));

            $column = $relation !== null ? str_replace('.', '_', $relation) . "_$column" : $column;

            $alias = $this->query->getDb()->quoteIdentifier([$column]);

            list($select, $values) = $this->query->getDb()
                ->getQueryBuilder()
                ->assembleSelect($subQuery->assembleSelect());

            return new AliasedExpression($alias, "($select)", null, ...$values);
        }
    }

    public function isSelectableColumn(string $name): bool
    {
        return $name === 'has_email';
    }

    public function rewriteColumnDefinition(ColumnDefinition $def, string $relation): void
    {
        $name = $def->getName();

        if ($this->isSelectableColumn($name)) {
            $def->setLabel(t('Has Email Address'));
        }
    }

    public function rewriteCondition(Filter\Condition $condition, $relation = null)
    {
        $column = substr($condition->getColumn(), strlen($relation));

        if ($this->isSelectableColumn($column)) {
            $type = 'email';

            $subQuery = $this->query->createSubQuery(new ContactAddress(), $relation)
                ->limit(1)
                ->columns([new Expression('1')])
                ->filter(Filter::equal('type', $type));

            if ($condition->getValue()) {
                if ($condition instanceof Filter\Unequal) {
                    return new NotExists($subQuery->assembleSelect()->resetOrderBy());
                } else {
                    return new Exists($subQuery->assembleSelect()->resetOrderBy());
                }
            } elseif ($condition instanceof Filter\Unequal) {
                return new Exists($subQuery->assembleSelect()->resetOrderBy());
            } else {
                return new NotExists($subQuery->assembleSelect()->resetOrderBy());
            }
        }
    }
}
