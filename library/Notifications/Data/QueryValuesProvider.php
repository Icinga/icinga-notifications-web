<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Data;

use BackedEnum;
use DateTime;
use DateTimeInterface;
use Generator;
use Icinga\Module\Notifications\Common\Auth;
use ipl\I18n\Translation;
use ipl\Orm\Exception\InvalidColumnException;
use ipl\Orm\Exception\InvalidRelationException;
use ipl\Orm\Query;
use ipl\Stdlib\BaseFilter;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar\SearchException;
use IteratorAggregate;
use Ramsey\Uuid\UuidInterface;

/**
 * Provide value suggestions for a given column and query
 *
 * Columns of the form `<relation>.tag.<name>` are resolved to the values of the
 * object's `object_id_tag` relation with the given tag name.
 *
 * Values are passed through the behaviors of the model the column belongs to and
 * then converted into scalars the search bar can render and parse back.
 */
class QueryValuesProvider implements IteratorAggregate
{
    use Auth;
    use BaseFilter;
    use Translation;

    /** @var Query The query to collect values from */
    protected Query $query;

    /** @var string The column to suggest values for */
    protected string $column;

    /** @var string The search term to suggest values for */
    protected string $searchTerm;

    /** @var Filter\Chain|Filter\Rule The search filter to apply */
    protected Filter\Chain|Filter\Rule $searchFilter;

    /**
     * Create a new QueryValuesProvider
     *
     * @param Query $query The query to collect values from
     * @param string $column The column to suggest values for
     * @param string $searchTerm The search term to suggest values for
     * @param Filter\Chain $searchFilter The search filter to apply
     */
    public function __construct(Query $query, string $column, string $searchTerm, Filter\Chain $searchFilter)
    {
        $this->query = $query;
        $this->column = $column;
        $this->searchTerm = $searchTerm;
        $this->searchFilter = $searchFilter;
    }

    public function getIterator(): Generator
    {
        $resolver = $this->query->getResolver();
        $model = $this->query->getModel();

        $columnPath = $resolver->qualifyPath($this->column, $model->getTableName());
        [$targetPath, $columnName] = preg_split('/(?<=\.tag)\.|\.(?=[^.]+$)/', $columnPath, 2);

        if (str_ends_with($targetPath, '.tag')) {
            $targetPath = substr($targetPath, 0, -3) . 'object_id_tag';
            $this->query->filter(Filter::like($targetPath . '.tag', $columnName));

            $columnName = 'value';
            $columnPath = $targetPath . '.value';
        }

        $inputFilter = Filter::like($columnPath, $this->searchTerm);
        $this->query->columns(['value' => $columnPath]);
        $this->query->orderBy($columnPath);

        if ($this->searchFilter instanceof Filter\None) {
            $this->query->filter($inputFilter);
        } elseif ($this->searchFilter instanceof Filter\All) {
            $this->searchFilter->add($inputFilter);

            // There may be columns part of $searchFilter which target the base table. These must be
            // optimized, otherwise they influence what we'll suggest to the user. (i.e. less)
            // The $inputFilter on the other hand must not be optimized, which it wouldn't, but since
            // we force optimization on its parent chain, we have to negate that.
            $this->searchFilter->metaData()->set('forceOptimization', true);
            $inputFilter->metaData()->set('forceOptimization', false);
        } else {
            $this->searchFilter = $inputFilter;
        }

        $this->query->filter($this->searchFilter);

        $this->applyRestrictions($this->query);
        if ($this->hasBaseFilter()) {
            $this->query->filter($this->getBaseFilter());
        }

        $this->query->getSelectBase()->distinct();

        try {
            $target = str_contains($targetPath, '.')
                ? $resolver->resolveRelation($targetPath)->getTarget()
                : $model;
            $behaviors = $resolver->getBehaviors($target);

            foreach ($this->query as $object) {
                $value = $this->normalizeValue(
                    $behaviors->retrieveProperty($object->value, $columnName),
                    $columnName
                );

                yield ['search' => $value, 'label' => $value];
            }
        } catch (InvalidColumnException $e) {
            throw new SearchException(sprintf($this->translate('"%s" is not a valid column'), $e->getColumn()));
        } catch (InvalidRelationException $e) {
            throw new SearchException(sprintf($this->translate('"%s" is not a valid relation'), $e->getRelation()));
        }
    }

    /**
     * Convert the given value into a scalar the search bar can render
     *
     * @param mixed $value
     * @param string $columnName
     *
     * @return mixed
     */
    protected function normalizeValue(mixed $value, string $columnName): mixed
    {
        if ($value instanceof DateTime) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        } elseif ($value instanceof UuidInterface) {
            return (string) $value;
        } elseif ($value instanceof BackedEnum) {
            return $value->value;
        } elseif (is_bool($value)) {
            return $value ? 'y' : 'n';
        } elseif (is_string($value) && str_ends_with($columnName, 'id')) {
            return bin2hex($value);
        }

        return $value;
    }
}
