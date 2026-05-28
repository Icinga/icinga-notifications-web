<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\Orm\Behaviors;
use ipl\Orm\Contract\PersistBehavior;
use ipl\Orm\Contract\PropertyBehavior;
use ipl\Orm\Contract\RetrieveBehavior;
use ipl\Orm\Query;
use ipl\Orm\Relation\BelongsTo;
use ipl\Orm\Relation\BelongsToMany;
use ipl\Orm\Resolver;
use ipl\Sql\Connection;
use ipl\Sql\ExpressionInterface;
use RuntimeException;

/**
 * Persists models and their related models to the database.
 *
 * The EntityManager is the write-side counterpart to {@see Query}. Whereas a query is bound to a model
 * class and operates on rows matching a filter, the EntityManager operates on concrete model instances:
 * a model carries everything needed to persist it (its table, columns, keys and behaviors), so no model
 * class has to be named separately.
 *
 * ```php
 * $em = new EntityManager($db);
 * $em->save($car);    // INSERT or UPDATE, depending on whether the model is new
 * $em->delete($car);
 * ```
 *
 * Whether {@see save()} inserts or updates is decided by {@see Model::isNew()}. Updates only write the
 * properties changed since the model was loaded ({@see Model::getDirtyMap()}). Set relations are cascaded
 * (parents first, then the model, then children and many-to-many links), all within a single transaction.
 */
class EntityManager
{
    /** @var Connection The database connection to persist to */
    protected Connection $db;

    /**
     * Create a new EntityManager for the given database connection
     *
     * @param Connection $db
     */
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Persist the given model and its set relations
     *
     * @param Model $model
     *
     * @return void
     */
    public function save(Model $model): void
    {
        $this->db->transaction(function () use ($model): void {
            $this->saveGraph($model);
        });
    }

    /**
     * Delete the given model from the database
     *
     * Does nothing if the model is new or has no primary key value. Nested records are expected to be
     * removed by the database (e.g. `ON DELETE CASCADE`).
     *
     * @param Model $model
     *
     * @return void
     */
    public function delete(Model $model): void
    {
        if ($model->isNew()) {
            return;
        }

        $behaviors = $this->resolverFor($model)->getBehaviors($model);

        $scope = $this->keyScope($model, $behaviors);
        if ($scope === null) {
            return;
        }

        $this->db->delete($model->getTableName(), $scope);
        $model->setNew(true);
        $model->markClean();
    }

    /**
     * Recursively persist the given model and its set relations
     *
     * @param Model $model
     *
     * @return void
     */
    protected function saveGraph(Model $model): void
    {
        $resolver = $this->resolverFor($model);
        $relations = $resolver->getRelations($model);

        // Snapshot what to cascade before persisting, since persisting resets change tracking. Only
        // explicitly set relations are considered (lazy loaders are closures, skipped by the iterator).
        // For loaded models, only relations the caller actually (re)assigned are cascaded.
        $set = iterator_to_array($model);
        $isNew = $model->isNew();
        $dirtyRelations = $isNew ? [] : $model->getDirtyMap();
        $shouldCascade = function (string $name) use ($set, $isNew, $dirtyRelations): bool {
            return isset($set[$name]) && ($isNew || isset($dirtyRelations[$name]));
        };

        // 1. Dependencies first: on the inverse side the foreign key is on this (source) table, so the
        //    related entity must be persisted beforehand and its key copied in. (BelongsTo)
        foreach ($relations as $name => $relation) {
            if (! $relation instanceof BelongsTo || ! $shouldCascade($name)) {
                continue;
            }

            $related = $set[$name];
            if (! $related instanceof Model) {
                continue;
            }

            $this->saveGraph($related);

            foreach ($relation->determineKeys($model) as $targetColumn => $sourceColumn) {
                $model->$sourceColumn = $related->$targetColumn;
            }
        }

        // 2. The model itself
        $this->persist($model, $resolver);

        // 3. Children: the foreign key is on the target table, so they are persisted afterwards with
        //    the model's now-known key copied in. (HasOne/HasMany)
        foreach ($relations as $name => $relation) {
            if ($relation instanceof BelongsTo || ! $shouldCascade($name)) {
                continue;
            }

            $keys = $relation->determineKeys($model);
            foreach ($this->asTraversable($set[$name]) as $child) {
                if (! $child instanceof Model) {
                    continue;
                }

                foreach ($keys as $targetColumn => $sourceColumn) {
                    $child->$targetColumn = $model->$sourceColumn;
                }

                $this->saveGraph($child);
            }
        }

        // 4. Many-to-many: persist both ends, then write the link rows into the junction table.
        //    Note: links are appended, not reconciled, so re-assigning a loaded relation may duplicate them.
        foreach ($relations as $name => $relation) {
            if (! $relation instanceof BelongsToMany || ! $shouldCascade($name)) {
                continue;
            }

            foreach ($this->asTraversable($set[$name]) as $target) {
                if (! $target instanceof Model) {
                    continue;
                }

                $this->saveGraph($target);
                $this->saveLink($relation, $model, $target);
            }
        }
    }

    /**
     * Insert or update the given model's own row
     *
     * @param Model $model
     * @param Resolver $resolver
     *
     * @return void
     */
    protected function persist(Model $model, Resolver $resolver): void
    {
        $behaviors = $resolver->getBehaviors($model);

        if (! $model->isNew() && ! $model->isDirty()) {
            return;
        }

        // Run non-property persist behaviors first so they can set/change properties (e.g. auto-timestamps)
        // before the row is built. PropertyBehaviors are intentionally skipped here — their per-value
        // conversion is applied below by extract() via persistProperty, and applying both would double-convert.
        $this->applyPersistBehaviors($behaviors, $model);

        if ($model->isNew()) {
            $this->db->insert($model->getTableName(), $this->extract($model, $behaviors));

            $keyName = $model->getKeyName();
            if (is_string($keyName) && ! $model->hasProperty($keyName)) {
                // Single auto-increment key that wasn't assigned by the application
                $id = $this->db->lastInsertId();
                if ($id !== false) {
                    $model->$keyName = $behaviors->retrieveProperty($id, $keyName);
                }
            }

            $this->applyRetrieveBehaviors($behaviors, $model);

            $model->setNew(false);
            $model->markClean();

            return;
        }

        $data = $this->extract($model, $behaviors, $model->getDirtyMap());
        if (empty($data)) {
            // Only relations changed; there is nothing to update on this row
            $model->markClean();

            return;
        }

        $scope = $this->keyScope($model, $behaviors);
        if ($scope === null) {
            throw new RuntimeException(sprintf(
                'Cannot update %s without a primary key value',
                get_class($model)
            ));
        }

        $this->db->update($model->getTableName(), $data, $scope);

        $this->applyRetrieveBehaviors($behaviors, $model);

        $model->markClean();
    }

    /**
     * Invoke non-property {@see PersistBehavior}s on the given model
     *
     * PropertyBehaviors are skipped: their per-value `persistProperty()` is applied during {@see static::extract()},
     * and invoking their `persist()` here would double-convert values.
     *
     * @param Behaviors $behaviors
     * @param Model $model
     *
     * @return void
     */
    protected function applyPersistBehaviors(Behaviors $behaviors, Model $model): void
    {
        foreach ($behaviors as $behavior) {
            if ($behavior instanceof PersistBehavior && ! $behavior instanceof PropertyBehavior) {
                $behavior->persist($model);
            }
        }
    }

    /**
     * Invoke non-property {@see RetrieveBehavior}s on the given model
     *
     * PropertyBehaviors are skipped: their per-value `retrieveProperty()` is applied targetedly
     * (e.g. on `lastInsertId`), and invoking their `retrieve()` here would double-convert values.
     *
     * @param Behaviors $behaviors
     * @param Model $model
     *
     * @return void
     */
    protected function applyRetrieveBehaviors(Behaviors $behaviors, Model $model): void
    {
        foreach ($behaviors as $behavior) {
            if ($behavior instanceof RetrieveBehavior && ! $behavior instanceof PropertyBehavior) {
                $behavior->retrieve($model);
            }
        }
    }

    /**
     * Build the column => value data for the given model, converting values for persistence
     *
     * @param Model $model
     * @param Behaviors $behaviors
     * @param ?array<string, true> $only Restrict to this set of property names (e.g. the dirty map), or null for all
     *
     * @return array<string, mixed>
     */
    protected function extract(Model $model, Behaviors $behaviors, ?array $only = null): array
    {
        $columns = $this->writableColumns($model);
        $data = [];

        foreach ($model as $property => $value) {
            if (! isset($columns[$property])) {
                continue;
            }

            if ($only !== null && ! isset($only[$property])) {
                continue;
            }

            $data[$columns[$property]] = $behaviors->persistProperty($value, $property);
        }

        return $data;
    }

    /**
     * Build a WHERE scope matching the given model by its primary key, converting values for persistence
     *
     * @param Model $model
     * @param Behaviors $behaviors
     *
     * @return ?array<string, mixed> Null if the model has no value for (part of) its primary key
     */
    protected function keyScope(Model $model, Behaviors $behaviors): ?array
    {
        $scope = [];

        foreach ((array) $model->getKeyName() as $key) {
            if (! $model->hasProperty($key)) {
                return null;
            }

            $scope[$key . ' = ?'] = $behaviors->persistProperty($model->$key, $key);
        }

        return $scope;
    }

    /**
     * Write the link row connecting the given source and target through the relation's junction table
     *
     * @param BelongsToMany $relation
     * @param Model $source
     * @param Model $target
     *
     * @return void
     */
    protected function saveLink(BelongsToMany $relation, Model $source, Model $target): void
    {
        $legs = [];
        foreach ($relation->setSource($source)->resolve() as $leg) {
            $legs[] = $leg;
        }

        if (count($legs) !== 2) {
            return;
        }

        $sourceBehaviors = $this->resolverFor($source)->getBehaviors($source);
        $targetBehaviors = $this->resolverFor($target)->getBehaviors($target);

        $row = [];

        // Leg 0: source -> junction, keys as [junctionColumn => sourceColumn]
        [, $junction, $sourceKeys] = $legs[0];
        foreach ($sourceKeys as $junctionColumn => $sourceColumn) {
            $row[$junctionColumn] = $sourceBehaviors->persistProperty($source->$sourceColumn, $sourceColumn);
        }

        // Leg 1: junction -> target, keys as [targetColumn => junctionColumn]
        [, , $targetKeys] = $legs[1];
        foreach ($targetKeys as $targetColumn => $junctionColumn) {
            $row[$junctionColumn] = $targetBehaviors->persistProperty($target->$targetColumn, $targetColumn);
        }

        if (empty($row)) {
            return;
        }

        $this->db->insert($junction->getTableName(), $row);
    }

    /**
     * Get the model's writable columns as a property => column map
     *
     * Expression columns are omitted as they cannot be written to.
     *
     * @param Model $model
     *
     * @return array<string, string>
     */
    protected function writableColumns(Model $model): array
    {
        $columns = [];

        foreach ((array) $model->getKeyName() as $key) {
            $columns[$key] = $key;
        }

        foreach ($model->getColumns() as $alias => $column) {
            if ($column instanceof ExpressionInterface) {
                continue;
            }

            if (is_int($alias)) {
                $columns[$column] = $column;
            } else {
                $columns[$alias] = $column;
            }
        }

        return $columns;
    }

    /**
     * Normalize a relation value (single model or collection) into something iterable
     *
     * @param mixed $value
     *
     * @return iterable<mixed>
     */
    protected function asTraversable(mixed $value): iterable
    {
        if ($value instanceof Model) {
            return [$value];
        }

        if (is_iterable($value)) {
            return $value;
        }

        return [];
    }

    /**
     * Get a resolver bound to the given model instance
     *
     * @param Model $model
     *
     * @return Resolver
     */
    protected function resolverFor(Model $model): Resolver
    {
        return (new Query())
            ->setDb($this->db)
            ->setModel($model)
            ->getResolver();
    }
}
