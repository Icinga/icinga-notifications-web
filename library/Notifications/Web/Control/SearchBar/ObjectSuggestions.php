<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Control\SearchBar;

use Generator;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Behavior\IcingaCustomVars;
use Icinga\Module\Notifications\Model\ObjectExtraTag;
use Icinga\Module\Notifications\Model\ObjectIdTag;
use Icinga\Module\Notifications\Util\ObjectSuggestionsCursor;
use ipl\Html\HtmlElement;
use ipl\I18n\Translation;
use ipl\Orm\Exception\InvalidColumnException;
use ipl\Orm\Exception\InvalidRelationException;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relation;
use ipl\Orm\Relation\HasOne;
use ipl\Orm\Resolver;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Seq;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\Control\SearchBar\Suggestions;
use PDO;
use Traversable;

class ObjectSuggestions extends Suggestions
{
    use Auth;
    use Translation;

    /** @var ?Model */
    protected ?Model $model = null;

    /**
     * Set the model to show suggestions for
     *
     * @param string|Model $model
     *
     * @return $this
     */
    public function setModel(string|Model $model): self
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $this->model = $model;

        return $this;
    }

    /**
     * Get the model to show suggestions for
     *
     * @return Model
     */
    public function getModel(): Model
    {
        if ($this->model === null) {
            throw new \LogicException(
                'You are accessing an unset property. Please make sure to set it beforehand.'
            );
        }

        return $this->model;
    }

    protected function shouldShowRelationFor(string $column): bool
    {
        if (
            str_contains($column, '.tag.')
            || str_contains($column, '.extra_tag.')
            || str_starts_with($column, IcingaCustomVars::HOST_PREFIX)
            || str_starts_with($column, IcingaCustomVars::SERVICE_PREFIX)
        ) {
            return false;
        }

        $tableName = $this->getModel()->getTableName();
        $columnPath = explode('.', $column);

        if (count($columnPath) > 2) {
            return true;
        }

        return $columnPath[0] !== $tableName;
    }

    protected function createQuickSearchFilter($searchTerm): Filter\Any|Filter\Chain
    {
        $model = $this->getModel();
        $resolver = $model::on(Database::get())->getResolver();

        $quickFilter = Filter::any();
        foreach ($model->getSearchColumns() as $column) {
            $where = Filter::like($resolver->qualifyColumn($column, $model->getTableName()), $searchTerm);
            $where->metaData()->set('columnLabel', $resolver->getColumnDefinition($where->getColumn())->getLabel());
            $quickFilter->add($where);
        }

        return $quickFilter;
    }

    protected function fetchValueSuggestions(
        $column,
        $searchTerm,
        Filter\Chain $searchFilter
    ): ObjectSuggestionsCursor {
        $model = $this->getModel();
        $query = $model::on(Database::get());
        $query->limit(static::DEFAULT_LIMIT);

        if (strpos($column, ' ') !== false) {
            // $column may be a label
            /** @var string $path */
            [$path, $_] = Seq::find(
                self::collectFilterColumns($query->getModel(), $query->getResolver()),
                $column,
                false
            );

            if ($path !== null) {
                $column = $path;
            }
        }

        $columnPath = $query->getResolver()->qualifyPath($column, $model->getTableName());
        /** @var string[] $splitted */
        $splitted = preg_split('/(?<=tag|extra_tag)\.|\.(?=[^.]+$)/', $columnPath, 2);
        [$targetPath, $columnName] = $splitted;

        $isTag = false;
        if (substr($targetPath, -4) === '.tag') {
            $isTag = true;
            $targetPath = substr($targetPath, 0, -3) . 'object_id_tag';
        } elseif (substr($targetPath, -10) === '.extra_tag') {
            $isTag = true;
            $targetPath = substr($targetPath, 0, -9) . 'object_extra_tag';
        } elseif (
            str_starts_with($column, IcingaCustomVars::HOST_PREFIX)
            || str_starts_with($column, IcingaCustomVars::SERVICE_PREFIX)
        ) {
            $isTag = true;
            $columnName = $column;
            $targetPath = $query->getResolver()->qualifyPath(
                'object.object_extra_tag',
                $model->getTableName()
            );
        }

        if (strpos($targetPath, '.') !== false) {
            try {
                $query->with($targetPath); // TODO: Remove this, once ipl/orm does it as early
            } catch (InvalidRelationException $e) {
                throw new SearchException(sprintf(t('"%s" is not a valid relation'), $e->getRelation()));
            }
        }

        if ($isTag) {
            $columnPath = $targetPath . '.value';
            $query->filter(Filter::like($targetPath . '.tag', $columnName));
        }

        $inputFilter = Filter::like($columnPath, $searchTerm);
        $query->columns($columnPath);
        $query->orderBy($columnPath);

        if ($searchFilter instanceof Filter\None) {
            $query->filter($inputFilter);
        } elseif ($searchFilter instanceof Filter\All) {
            $searchFilter->add($inputFilter);

            // There may be columns part of $searchFilter which target the base table. These must be
            // optimized, otherwise they influence what we'll suggest to the user. (i.e. less)
            // The $inputFilter on the other hand must not be optimized, which it wouldn't, but since
            // we force optimization on its parent chain, we have to negate that.
            $searchFilter->metaData()->set('forceOptimization', true);
            $inputFilter->metaData()->set('forceOptimization', false);
        } else {
            $searchFilter = $inputFilter;
        }

        $query->filter($searchFilter);
        $this->applyRestrictions($query);

        try {
            return (new ObjectSuggestionsCursor($query->getDb(), $query->assembleSelect()->distinct()))
                ->setFetchMode(PDO::FETCH_COLUMN);
        } catch (InvalidColumnException $e) {
            throw new SearchException(sprintf(t('"%s" is not a valid column'), $e->getColumn()));
        }
    }

    protected function fetchColumnSuggestions($searchTerm): Generator
    {
        $model = $this->getModel();
        $query = $model::on(Database::get());

        // Ordinary columns first
        foreach (self::collectFilterColumns($model, $query->getResolver()) as $columnName => $columnMeta) {
            yield $columnName => $columnMeta;
        }

        $parsedArrayVars = [];

        // Custom variables only after the columns are exhausted and there's actually a chance the user sees them
        foreach ([new ObjectIdTag(), new ObjectExtraTag()] as $model) {
            $titleAdded = false;
            /** @var ObjectIdTag|ObjectExtraTag $tag */
            foreach ($this->queryTags($model, $searchTerm) as $tag) {
                $isIdTag = $tag instanceof ObjectIdTag;

                if (! $titleAdded) {
                    $titleAdded = true;
                    $this->addHtml(HtmlElement::create(
                        'li',
                        ['class' => static::SUGGESTION_TITLE_CLASS],
                        $isIdTag ? t('Object Tags') : t('Object Extra Tags')
                    ));
                }

                if (
                    str_starts_with($tag->tag, IcingaCustomVars::HOST_PREFIX)
                    || str_starts_with($tag->tag, IcingaCustomVars::SERVICE_PREFIX)
                ) {
                    $search = $name = $tag->tag;
                    if (preg_match('/\w+(?:\[(\d*)])+$/', $name, $matches)) {
                        $name = substr($name, 0, -(strlen($matches[1]) + 2));
                        if (isset($parsedArrayVars[$name])) {
                            continue;
                        }

                        $parsedArrayVars[$name] = true;
                        $search = $name . '[*]';
                    }

                    yield $search => sprintf($this->translate(
                        ucfirst(substr($name, 0, strpos($name, '.'))) . ' %s',
                        '..<customvar-name>'
                    ), substr($name, strlen(
                        str_starts_with($name, IcingaCustomVars::HOST_PREFIX)
                            ? IcingaCustomVars::HOST_PREFIX
                            : IcingaCustomVars::SERVICE_PREFIX
                    )));
                } else {
                    $relation = $isIdTag ? 'object.tag' : 'object.extra_tag';

                    yield $relation . '.' . $tag->tag => ucfirst($tag->tag);
                }
            }
        }
    }

    /**
     * Prepare query with all available tags/extra_tags from provided model matching the given term
     *
     * @param Model $model The model to fetch tag/extra_tag from
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

        $tags->getSelectBase()->groupBy($tagColumn)->limit(static::DEFAULT_LIMIT);

        return $tags;
    }

    protected function matchSuggestion($path, $label, $searchTerm): bool
    {
        if (preg_match('/[_.](id)$/', $path)) {
            // Only suggest exotic columns if the user knows the full column path
            return substr($path, strrpos($path, '.') + 1) === trim($searchTerm, ' *');
        }

        return parent::matchSuggestion($path, $label, $searchTerm);
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
