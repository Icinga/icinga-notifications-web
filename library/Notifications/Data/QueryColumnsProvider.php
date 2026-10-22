<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Data;

use Generator;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\ObjectIdTag;
use ipl\I18n\Translation;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relation;
use ipl\Orm\Relation\HasOne;
use ipl\Orm\Resolver;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar\Suggestions;
use IteratorAggregate;
use Traversable;

/**
 * Provide column and object tag suggestions for a given query
 */
class QueryColumnsProvider implements IteratorAggregate
{
    use Auth;
    use Translation;

    /** @var Query The query to collect columns from */
    protected Query $query;

    /** @var string The search term to suggest columns for */
    protected string $searchTerm;

    /**
     * Create a new QueryColumnsProvider
     *
     * @param Query $query The query to collect columns from
     * @param string $searchTerm The search term to suggest columns for
     */
    public function __construct(Query $query, string $searchTerm = '*')
    {
        $this->query = $query;
        $this->searchTerm = $searchTerm;
    }

    public function getIterator(): Generator
    {
        $model = $this->query->getModel();

        // Ordinary columns first
        foreach (self::collectFilterColumns($model, $this->query->getResolver()) as $columnName => $columnMeta) {
            if ($this->matchSuggestion($columnName, $columnMeta, $this->searchTerm)) {
                yield ['search' => $columnName, 'label' => $columnMeta, 'group' => $this->translate('Columns')];
            }
        }

        if (! $this->query->getResolver()->getRelations($model)->has('object')) {
            return;
        }

        // Object tags only after the columns are exhausted and there's actually a chance the user sees them
        /** @var ObjectIdTag $tag */
        foreach ($this->queryTags(new ObjectIdTag(), $this->searchTerm) as $tag) {
            $columnName = 'object.tag.' . $tag->tag;
            $columnMeta = ucfirst($tag->tag);
            if ($this->matchSuggestion($columnName, $columnMeta, $this->searchTerm)) {
                yield ['search' => $columnName, 'label' => $columnMeta, 'group' => $this->translate('Object Tags')];
            }
        }
    }

    /**
     * Return whether the relation should be shown for the given column of the given model
     *
     * @param string $column
     * @param Model $model
     *
     * @return bool
     */
    public static function shouldShowRelationFor(string $column, Model $model): bool
    {
        if (str_contains($column, '.tag.')) {
            return false;
        }

        $tableName = $model->getTableName();
        $columnPath = explode('.', $column);

        if (count($columnPath) > 2) {
            return true;
        }

        return $columnPath[0] !== $tableName;
    }

    /**
     * Prepare query with all available tags from provided model matching the given term
     *
     * @param Model $model The model to fetch tag from
     * @param string $searchTerm The given search term
     *
     * @return Query
     */
    protected function queryTags(Model $model, string $searchTerm): Query
    {
        $tags = $model::on(Database::get())
            ->columns('tag')
            ->filter(Filter::like('tag', $searchTerm));
        $this->applyRestrictions($tags);

        $resolver = $tags->getResolver();
        $tagColumn = $resolver->qualifyColumn('tag', $resolver->getAlias($tags->getModel()));

        $tags->getSelectBase()->groupBy($tagColumn)->limit(Suggestions::DEFAULT_LIMIT);

        return $tags;
    }

    /**
     * Check whether the given column path and label match the search term
     *
     * @param string $path
     * @param string $label
     * @param string $searchTerm
     *
     * @return bool
     */
    protected function matchSuggestion($path, $label, $searchTerm): bool
    {
        if (preg_match('/[_.](id)$/', $path)) {
            // Only suggest exotic columns if the user knows the full column path
            return substr($path, strrpos($path, '.') + 1) === trim($searchTerm, ' *');
        }

        return fnmatch($searchTerm, $label, FNM_CASEFOLD) || fnmatch($searchTerm, $path, FNM_CASEFOLD);
    }

    /**
     * Collect all columns of this model and its relations that can be used for filtering
     *
     * @param Model $model
     * @param Resolver $resolver
     *
     * @return Traversable
     */
    public static function collectFilterColumns(Model $model, Resolver $resolver): Traversable
    {
        $models = [$model->getTableName() => $model];
        self::collectRelations($resolver, $model, $models, []);

        foreach ($models as $path => $targetModel) {
            foreach ($resolver->getColumnDefinitions($targetModel) as $columnName => $definition) {
                yield $path . '.' . $columnName => $definition->getLabel();
            }
        }
    }

    /**
     * Collect all direct relations of the given model
     *
     * A direct relation is either a direct descendant of the model
     * or a descendant of such related in a to-one cardinality.
     *
     * @param Resolver $resolver
     * @param Model $subject
     * @param array $models
     * @param array $path
     *
     * @return void
     */
    protected static function collectRelations(Resolver $resolver, Model $subject, array &$models, array $path): void
    {
        foreach ($resolver->getRelations($subject) as $name => $relation) {
            /** @var Relation $relation */
            $isHasOne = $relation instanceof HasOne;
            if (empty($path)) {
                $relationPath = [$name];
                if ($isHasOne) {
                    array_unshift($relationPath, $subject->getTableName());
                }

                $relationPath = array_merge($path, $relationPath);
                $models[join('.', $relationPath)] = $relation->getTarget();
                self::collectRelations($resolver, $relation->getTarget(), $models, $relationPath);
            }
        }
    }
}
