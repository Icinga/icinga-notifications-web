<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use ipl\Orm\Query;
use IteratorAggregate;
use LogicException;
use RuntimeException;
use Traversable;

/**
 * Container for members of a to-many relation
 *
 * For a HasMany relation, {@see self::attach()} and {@see self::detach()} insert/delete the database rows
 * of the given models.
 * For a BelongsToMany, only the links from the source model will be inserted/deleted, the target itself remains
 * untouched.
 *
 * By default, rows that are not included in {@see self::$attach} or {@see self::$detach} remain untouched.
 * Calling {@see self::sync()} will put the collection into replace mode, which will delete what is not contained
 * in {@see self::$attach}
 *
 * Members may also be edited in place and will be cascaded by {@see EntityManager::save()} if they have
 * been modified.
 *
 * @template TModel of Model
 * @implements IteratorAggregate<int, TModel>
 */
class Collection implements IteratorAggregate, Countable
{
    /**
     * Stored members, still as the unread {@see Query}
     *
     * Drained into {@see self::$base} once on first read, so the query is not run eagerly.
     *
     * @var ?Query<TModel>
     */
    private ?Query $source = null;

    /**
     * Stored members once {@see self::$source} has been read, cached for repeated reads
     *
     * @var ?array<string, TModel>
     */
    private ?array $base = null;

    /** @var array<string, TModel> Models whose links/rows must exist after the next {@see EntityManager::save()} */
    private array $attach = [];

    /** @var array<string, TModel> Models whose links/rows must be removed on the next {@see EntityManager::save()} */
    private array $detach = [];

    /** @var bool Whether {@see self::sync()} requested a replace (remove whatever is not in {@see self::$attach}) */
    private bool $replace = false;

    /** @param class-string<TModel> $memberType */
    public function __construct(
        private string $memberType
    ) {
        if (! is_a($this->memberType, Model::class, true)) {
            throw new LogicException(
                'Collection member type must be a subclass of ' . Model::class
            );
        }
    }

    /**
     * Create a collection whose members are merged with existing ones on the next save
     *
     * @template TValue of Model
     *
     * @param class-string<TValue> $memberType
     * @param iterable<TValue> $values
     *
     * @return static<TValue>
     */
    public static function create(string $memberType, iterable $values): static
    {
        $collection = new static($memberType);
        foreach ($values as $model) {
            $collection->attach($model);
        }

        return $collection;
    }

    /**
     * Create a collection from members who are treated as already existing db rows
     *
     * @template TRow of Model
     *
     * @param Query<TRow> $source
     *
     * @return static<TRow>
     */
    public static function fromLoaded(Query $source): static
    {
        $collection = new static($source->getModel()::class);
        $collection->setSource($source);

        return $collection;
    }

    /**
     * Set the source to materialize the base from
     *
     * @param Query<TModel> $source
     *
     * @return void
     */
    private function setSource(Query $source): void
    {
        $this->source = $source;
    }

    /**
     * Get the {@see Query} the Collection was created from
     *
     * The query may be modified e.g. by calling {@see Query::filter()} on it, but only before the collection
     * itself is iterated or {@see self::count()} is called.
     *
     * Modifications made to a Model yielded by the query will NOT be persisted by {@see EntityManager::save()}.
     * Traverse the collection and modify models in place, or pass a modified {@see Model} to {@see self::attach()}
     * if changes should be persisted.
     *
     * @return Query<TModel>
     *
     * @throws LogicException If the collection was not created from a query, or the query has already been read
     */
    public function query(): Query
    {
        if ($this->source === null) {
            throw new LogicException('Collection was created without a query, or the query was already read');
        }

        return $this->source;
    }

    /**
     * Additively register the given model as a member of this relation
     *
     * @param TModel $model
     *
     * @return $this
     * @throws InvalidArgumentException If the model is not of the correct type
     */
    public function attach(Model $model): static
    {
        $this->assertMemberType($model);

        $id = $this->identify($model);
        unset($this->detach[$id]);
        if (! isset($this->base[$id])) {
            $this->attach[$id] = $model;
        } elseif ($model !== $this->base[$id]) {
            throw new LogicException(
                'Collection already contains a different model with the same primary key'
            );
        }

        return $this;
    }

    /**
     * Register the given model to be removed from this relation
     *
     * @param TModel $model
     *
     * @return $this
     * @throws InvalidArgumentException If the model is not of the correct type
     */
    public function detach(Model $model): static
    {
        $this->assertMemberType($model);

        $id = $this->identify($model);
        unset($this->attach[$id]);
        $this->detach[$id] = $model;

        return $this;
    }

    /**
     * Replace this relation's members with exactly the given models
     *
     * Anything currently stored that is not in $models is removed on save.
     *
     * @param iterable<TModel> $models
     *
     * @return $this
     */
    public function sync(iterable $models): static
    {
        $this->attach = [];
        $this->detach = [];
        $this->replace = true;
        foreach ($models as $model) {
            $this->attach($model);
        }

        return $this;
    }

    /**
     * Get whether {@see self::sync()} put this collection into replace mode
     *
     * @return bool
     */
    public function isReplacing(): bool
    {
        return $this->replace;
    }

    /**
     * Get the members to persist on save: staged attachments plus in-place-modified loaded members
     *
     * @return array<int, TModel>
     */
    public function getMembersToSave(): array
    {
        if ($this->replace) {
            return array_values($this->attach);
        }

        $members = array_filter($this->base ?? [], function ($model) {
            return $model->isModified();
        });

        return array_values(array_merge($members, $this->attach));
    }

    /**
     * Get the models to delete/unlink on save
     *
     * @return array<int, TModel>
     */
    public function getDetachments(): array
    {
        return array_values($this->detach);
    }

    /**
     * Get the members that must be part of the relation after a save and were explicitly attached by
     * {@see self::attach()} or {@see self::sync()}
     *
     * @return array<int, TModel>
     */
    public function getAttachments(): array
    {
        return array_values($this->attach);
    }

    /**
     * Whether anything must be persisted on the next save
     *
     * @return bool
     */
    public function hasPendingChanges(): bool
    {
        if ($this->replace || ! empty($this->attach) || ! empty($this->detach)) {
            return true;
        }

        foreach ($this->base ?? [] as $model) {
            if ($model->isModified()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reset the pending write state after the collection has been persisted
     *
     * @return void
     */
    public function clearPendingChanges(): void
    {
        $this->attach = [];
        $this->detach = [];
        $this->replace = false;
    }

    /**
     * @return Traversable<int, TModel>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * The staged members: base merged with attachments, minus detachments
     *
     * @return array<int, TModel>
     */
    private function all(): array
    {
        if ($this->replace) {
            return array_values($this->attach);
        }

        $members = [];
        foreach ($this->materializeBase() as $id => $model) {
            if (isset($this->attach[$id]) && $this->attach[$id] !== $model) {
                throw new LogicException(
                    'Collection already contains a different model with the same primary key'
                );
            }

            $members[$id] = $model;
        }

        foreach ($this->detach as $id => $_) {
            unset($members[$id]);
        }

        return array_values(array_merge($members, $this->attach));
    }

    /**
     * The stored members, read from the source query once and cached
     *
     * @return array<string, TModel>
     */
    private function materializeBase(): array
    {
        if ($this->base === null) {
            $this->base = [];
            foreach ($this->source ?? [] as $model) {
                $this->base[$this->identify($model)] = $model->setNew(false);
            }

            $this->source = null;
        }

        return $this->base;
    }

    /**
     * Return the primary key of the model, or an object hash if it is not set
     *
     * @param TModel $model
     *
     * @return string
     * @throws RuntimeException In case the model's primary key is not a string or int
     */
    private function identify(Model $model): string
    {
        $values = [];
        foreach ((array) $model->getKeyName() as $column) {
            if (! $model->hasProperty($column) || $model->$column === null) {
                return 'obj#' . spl_object_id($model);
            } elseif (! is_string($model->$column) && ! is_int($model->$column)) {
                throw new RuntimeException(sprintf(
                    'Primary key column %s of model %s must be a string or int, got %s',
                    $column,
                    get_class($model),
                    get_debug_type($model->$column)
                ));
            }

            $values[] = (string) $model->$column;
        }

        return 'pk#' . implode('|', $values);
    }

    /**
     * Assert the given model is of the expected type
     *
     * @param Model $model
     *
     * @return void
     */
    private function assertMemberType(Model $model): void
    {
        if (! is_a($model, $this->memberType)) {
            throw new InvalidArgumentException(sprintf(
                'Collection of %s is incompatible with type %s',
                $this->memberType,
                get_class($model)
            ));
        }
    }
}
