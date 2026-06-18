<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use DateTime;
use ipl\Orm\Behaviors;
use ipl\Orm\Query;
use ipl\Orm\Relation;
use ipl\Orm\Relation\BelongsTo;
use ipl\Orm\Relation\BelongsToMany;
use ipl\Orm\Relation\Junction;
use ipl\Orm\Resolver;
use ipl\Sql\Connection;
use ipl\Sql\ExpressionInterface;
use ipl\Sql\Insert;
use ipl\Sql\Select;
use PDO;
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
 * $em->save($car);                 // INSERT or UPDATE, depending on whether the model is new
 * $em->save($car->markDeleted());  // DELETE or soft-delete, depending on the model
 * ```
 *
 * Whether {@see save()} inserts or updates is decided by {@see Model::isNew()}. Updates only write the
 * properties changed since the model was loaded ({@see Model::getModifiedProperties()}). Set relations are cascaded
 * (parents first, then the model, then children and many-to-many links), all within a single transaction.
 */
class EntityManager
{
    /** @var Connection The database connection to persist to */
    protected Connection $db;

    /**
     * Cache of writable column maps populated by {@see writableColumns()}, keyed by model class
     *
     * @var array<class-string, array<string, string>>
     */
    private array $writableColumnCache = [];

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
        if ($this->db->inTransaction()) {
            $this->saveGraph($model);
        } else {
            $this->db->transaction(function () use ($model): void {
                $this->saveGraph($model);
            });
        }
    }

    /**
     * Hard-delete the given model's row and reset it to a fresh state
     *
     * Internal helper for the delete flow: callers mark a model with {@see Model::markDeleted()} and
     * pass it to {@see save()}. Does nothing if the model is new or has no primary key value. Nested
     * records are expected to be removed by the database (e.g. `ON DELETE CASCADE`).
     *
     * @param Model $model
     *
     * @return void
     */
    protected function delete(Model $model): void
    {
        if ($model->isNew()) {
            return;
        }

        $behaviors = $this->resolverFor($model)->getBehaviors($model);

        $condition = $this->createPrimaryKeyCondition($model, $behaviors);
        if ($condition === null) {
            return;
        }

        $this->db->delete($model->getTableName(), $condition);
        foreach ((array) $model->getKeyName() as $k) {
            unset($model->$k);
        }

        $model->setNew(true);
        $model->clearModifiedProperties();
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
        //TODO maybe merge into other guards to clean up code
        if ($model->isNew() && $model->isDeleted()) {
            return;
        }

        $resolver = $this->resolverFor($model);

        // Snapshot what to cascade before persisting, since persisting resets change tracking. Only
        // explicitly set relations are considered (lazy loaders are closures, skipped by the iterator).
        // A relation is cascaded when the caller (re)assigned it, or when its already-materialized value
        // has pending changes of its own — so an in-place edit to a loaded related model is persisted.
        // An explicit `null` clears the relation.
        $set = iterator_to_array($model);
        $isNew = $model->isNew();
        $modifiedRelations = $isNew ? [] : $model->getModifiedProperties();

        /** @var array<string, BelongsTo> $dependencies */
        $dependencies = [];
        /** @var array<string, Relation> $children */
        $children = [];
        /** @var array<string, BelongsToMany> $links */
        $links = [];
        foreach ($resolver->getRelations($model) as $name => $relation) {
            if (
                ! array_key_exists($name, $set) ||
                ! $isNew && ! isset($modifiedRelations[$name]) && ! $this->hasPendingChanges($set[$name])
            ) {
                // The relation has no changes to persist
                continue;
            }

            if ($relation instanceof BelongsTo) {
                $dependencies[$name] = $relation;
            } elseif ($relation instanceof BelongsToMany) {
                $links[$name] = $relation;
            } else {
                $children[$name] = $relation;
            }
        }

        // In the delete case children must be saved before the model
        if ($model->isDeleted()) {
            // Many-to-many relations
            $this->saveManyToMany($model, $links, $set);

            // Children
            foreach ($children as $name => $relation) {
                foreach ($this->asTraversable($set[$name]) as $child) {
                    if ($child instanceof Model && $child->isDeleted()) {
                        $this->saveGraph($child);
                    }
                }
            }

            // The model itself is soft or hard deleted according to its own prefernce
            $this->persist($model, $resolver->getBehaviors($model));

            // walk parents and apply deletes or pending updates
            foreach ($dependencies as $name => $relation) {
                $related = $set[$name];
                if ($related instanceof Model && ($related->isDeleted() || $related->isModified())) {
                    $this->saveGraph($related);
                }
            }
        } else {
            // 1. Dependencies first: on the inverse side the foreign key is on this (source) table, so the
            //    related entity must be persisted beforehand and its key copied in. (BelongsTo)
            foreach ($dependencies as $name => $relation) {
                $related = $set[$name];
                if ($related === null) {
                    foreach ($relation->determineKeys($model) as $sourceColumn) {
                        $model->$sourceColumn = null;
                    }

                    continue;
                }

                if (! $related instanceof Model) {
                    continue;
                }

                $this->saveGraph($related);

                foreach ($relation->determineKeys($model) as $targetColumn => $sourceColumn) {
                    $model->$sourceColumn = $related->$targetColumn;
                }
            }

            // 2. The model itself
            $this->persist($model, $resolver->getBehaviors($model));

            // 3. Children: the foreign key is on the target table, so they are persisted afterwards with
            //    the model's now-known key copied in. (HasOne/HasMany)
            foreach ($children as $name => $relation) {
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

            // 4. Many-to-many: persist both ends, then reconcile the junction.
            $this->saveManyToMany($model, $links, $set);
        }
    }

    /**
     * Save targets of the given many-to-many relations and reconcile each junction
     *
     * @param Model $model The source model
     * @param array<string, BelongsToMany> $links The model's set & modified many-to-many relations, keyed by name
     * @param array<string, mixed> $set The model's set properties, as snapshotted by {@see saveGraph()}
     *
     * @return void
     */
    private function saveManyToMany(Model $model, array $links, array $set): void
    {
        foreach ($links as $name => $relation) {
            $targets = [];
            foreach ($this->asTraversable($set[$name]) as $target) {
                if (! $target instanceof Model) {
                    continue;
                }

                $this->saveGraph($target);
                $targets[] = $target;
            }

            $this->reconcileJunction($relation, $model, $targets);
        }
    }

    /**
     * Get whether the given relation value carries unsaved changes that should be cascaded
     *
     * @param mixed $value A relation value as snapshotted in the set (a model, a collection of models, or null)
     *
     * @return bool
     */
    private function hasPendingChanges(mixed $value): bool
    {
        if ($value instanceof Model) {
            return $value->isNew() || $value->isModified() || $value->isDeleted();
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($item instanceof Model && ($item->isNew() || $item->isModified() || $item->isDeleted())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Insert or update the given model's own row
     *
     * @param Model $model
     * @param Behaviors $behaviors
     *
     * @return void
     */
    protected function persist(Model $model, Behaviors $behaviors): void
    {
        if (! $model->isNew() && ! $model->isModified() && ! $model->isDeleted()) {
            return;
        }

        // TODO: this should be moved into delete(). and maybe call delete() from saveGraph() directly?
        if ($model->isDeleted() && ! $model->useSoftDelete()) {
            if ($model->isNew()) {
                $model->clearModifiedProperties();

                return;
            }

            if (! $model->useSoftDelete()) {
                $this->delete($model);

                return;
            }
        }

        // Insert case
        if ($model->isNew()) {
            $this->stampChangedAt($model);
            $this->db->insert($model->getTableName(), $this->extract($model, $behaviors));

            $keyName = $model->getKeyName();
            if (is_string($keyName) && ! $model->hasProperty($keyName)) {
                // Single auto-increment key that wasn't assigned by the application
                $id = $this->db->lastInsertId();
                if ($id !== false) {
                    $model->$keyName = $behaviors->retrieveProperty((int) $id, $keyName);
                }
            }

            $model->setNew(false);
            $model->clearModifiedProperties();

            return;
        }

        foreach ((array) $model->getKeyName() as $key) {
            if ($model->isModified($key)) {
                throw new RuntimeException(sprintf(
                    'Cannot update %s: primary key column "%s" was modified',
                    get_class($model),
                    $key
                ));
            }
        }

        $data = $this->extract($model, $behaviors, $model->getModifiedProperties());
        if (empty($data)) {
            // Only relations changed; there is nothing to update on this row
            $model->clearModifiedProperties();

            return;
        }

        $condition = $this->createPrimaryKeyCondition($model, $behaviors);
        if ($condition === null) {
            throw new RuntimeException(
                sprintf(
                    'Cannot update %s without a primary key value',
                    get_class($model)
                )
            );
        }

        // Stamp only now that we know an UPDATE will actually go out. The stamp adds
        // `changed_at` to the modified set, so re-extract to pick it up.
        $this->stampChangedAt($model);
        $data = $this->extract($model, $behaviors, $model->getModifiedProperties());

        $this->db->update($model->getTableName(), $data, $condition);

        $model->clearModifiedProperties();
    }

    /**
     * Stamp the model's `changed_at` column with the current time if it has one
     *
     * Schema-wide convention; not implemented as a behavior so individual models don't have to opt in.
     *
     * @param Model $model
     *
     * @return void
     */
    protected function stampChangedAt(Model $model): void
    {
        if (isset($this->writableColumns($model)['changed_at'])) {
            $model->changed_at = $this->now();
        }
    }

    /**
     * Get the current time used for {@see stampChangedAt()}
     *
     * Override in a subclass to inject a fixed clock for testing.
     *
     * @return DateTime
     */
    protected function now(): DateTime
    {
        return new DateTime();
    }

    /**
     * Build the column => value data for the given model, converting values for persistence
     *
     * @param Model $model
     * @param Behaviors $behaviors
     * @param ?array<string, true> $only Restrict to this set of property names, or `null` for all
     *
     * @return array<string, mixed>
     */
    protected function extract(Model $model, Behaviors $behaviors, ?array $only = null): array
    {
        $columns = $this->writableColumns($model);
        $data = [];

        // Restrict to the given property set (e.g. the modified set) or fall back to all set properties.
        $properties = $only ?? $model;
        foreach ($properties as $property => $_) {
            if (! isset($columns[$property])) {
                continue;
            }

            $data[$columns[$property]] = $behaviors->persistProperty($model->$property, $property);
        }

        return $data;
    }

    /**
     * Build a WHERE condition matching the given model by its primary key, converting values for persistence
     *
     * @param Model $model
     * @param Behaviors $behaviors
     *
     * @return ?array<string, mixed> Null if the model has no value for (part of) its primary key
     */
    protected function createPrimaryKeyCondition(Model $model, Behaviors $behaviors): ?array
    {
        $columns = [];

        foreach ((array) $model->getKeyName() as $key) {
            if (! $model->hasProperty($key)) {
                return null;
            }

            $columns[$key] = $behaviors->persistProperty($model->$key, $key);
        }

        return $this->createCondition($columns);
    }

    /**
     * Reconcile the junction so it links the given source to exactly the given targets
     *
     * Assignment is authoritative (implicit reconcile): links no longer present are removed and links
     * not yet stored are inserted, while links already present are left untouched — so re-saving an
     * unchanged relation is a no-op rather than a duplicate, and assigning a different set replaces the
     * previous one. Additive behavior is the caller's job (assign the union of the loaded relation and
     * the new targets).
     *
     * Only the junction rows are touched, never the target rows. The current set is read back from the
     * junction because the relation is lazy and its prior value was never loaded.
     *
     * If the junction is a soft-delete table ({@see isSoftDeleteJunction()}), reconciliation is
     * soft-delete aware: removed links are marked deleted, previously soft-deleted links are revived
     * rather than re-inserted, and `changed_at` is stamped on every touch ({@see reconcileSoftDeleteJunction()}).
     * Otherwise the junction is reconciled with hard deletes.
     *
     * @param BelongsToMany $relation
     * @param Model $source
     * @param list<Model> $targets
     *
     * @return void
     */
    protected function reconcileJunction(BelongsToMany $relation, Model $source, array $targets): void
    {
        $legs = [];
        foreach ($relation->setSource($source)->resolve() as $leg) {
            $legs[] = $leg;
        }

        $sourceBehaviors = $this->resolverFor($source)->getBehaviors($source);

        // Leg 0: source -> junction, keys as [junctionColumn => sourceColumn]. The same for every link.
        [, $junction, $sourceKeys] = $legs[0];
        $sourceColumns = [];
        foreach ($sourceKeys as $junctionColumn => $sourceColumn) {
            $sourceColumns[$junctionColumn] = $sourceBehaviors->persistProperty($source->$sourceColumn, $sourceColumn);
        }

        // Leg 1: junction -> target, keys as [targetColumn => junctionColumn]. No many-to-many target
        // in this module has a compound key, so a single column identifies a link's target side.
        [, , $targetKeys] = $legs[1];

        $targetColumn = array_key_first($targetKeys);
        $junctionColumn = $targetKeys[$targetColumn];

        $desired = [];
        foreach ($targets as $target) {
            $targetBehaviors = $this->resolverFor($target)->getBehaviors($target);
            $value = $targetBehaviors->persistProperty($target->$targetColumn, $targetColumn);
            $desired[(string) $value] = $value;
        }

        if ($this->isSoftDeleteJunction($junction)) {
            $this->reconcileSoftDeleteJunction($junction, $sourceColumns, $junctionColumn, $desired);

            return;
        }

        $table = $junction->getTableName();
        $stored = $this->fetchJunctionRows($table, $sourceColumns, $junctionColumn);

        foreach ($stored as $identity => $value) {
            if (! isset($desired[$identity])) {
                $this->db->delete(
                    $table,
                    $this->createCondition(array_merge($sourceColumns, [$junctionColumn => $value]))
                );
            }
        }

        $missing = [];
        foreach ($desired as $identity => $value) {
            if (! isset($stored[$identity])) {
                $missing[] = array_merge($sourceColumns, [$junctionColumn => $value]);
            }
        }

        $this->insertRows($table, $missing);
    }

    /**
     * Get whether the given junction uses soft deletes
     *
     * @param Junction|Model $junction A generic {@see Junction} or a junction model
     *
     * @return bool
     */
    protected function isSoftDeleteJunction(Junction|Model $junction): bool
    {
        return  ! $junction instanceof Junction && $junction->useSoftDelete();
    }

    /**
     * Read the target value of the links currently stored for the given source, keyed by that value
     *
     * @param string $table
     * @param array<string, mixed> $sourceColumns
     * @param string $junctionColumn The junction's target-side column to read back
     *
     * @return array<string, mixed>
     */
    private function fetchJunctionRows(string $table, array $sourceColumns, string $junctionColumn): array
    {
        $select = (new Select())
            ->from($table)
            ->columns($junctionColumn)
            ->where($this->createCondition($sourceColumns));

        $stored = [];
        foreach ($this->db->select($select)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = $row[$junctionColumn];
            $stored[(string) $value] = $value;
        }

        return $stored;
    }

    /**
     * Reconcile a soft-delete junction to exactly the desired links
     *
     * Links no longer desired but still active are soft-deleted; desired links that are currently
     * soft-deleted are revived; desired links not stored at all are inserted; active links that remain
     * desired are left untouched. Every soft-delete, revival and insert stamps `changed_at`.
     *
     * The `deleted` and `changed_at` columns are written using their schema-wide storage forms directly
     * (the `'y'`/`'n'` enum and a millisecond timestamp), rather than routing through the junction
     * model's behaviors — the same way {@see persist()} treats `changed_at` as a fixed convention. Every
     * soft-delete junction in the schema carries both columns, so neither is treated as optional.
     *
     * @param Model $junction A junction model
     * @param array<string, mixed> $sourceColumns
     * @param string $junctionColumn
     * @param array<string, mixed> $desired Target values keyed by identity
     *
     * @return void
     */
    private function reconcileSoftDeleteJunction(
        Model $junction,
        array $sourceColumns,
        string $junctionColumn,
        array $desired
    ): void {
        $table = $junction->getTableName();
        $changedAt = (int) $this->now()->format('Uv');

        $select = (new Select())
            ->from($table)
            ->columns([$junctionColumn, 'deleted'])
            ->where($this->createCondition($sourceColumns));

        $stored = [];
        foreach ($this->db->select($select)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored[(string) $row[$junctionColumn]] = [
                'value' => $row[$junctionColumn],
                'deleted' => $row['deleted'] === 'y',
            ];
        }

        // Soft-delete links that are no longer desired but still active.
        foreach ($stored as $identity => $link) {
            if (! isset($desired[$identity]) && ! $link['deleted']) {
                $this->markJunctionRow(
                    $table,
                    array_merge($sourceColumns, [$junctionColumn => $link['value']]),
                    'y',
                    $changedAt
                );
            }
        }

        // Revive desired links that are soft-deleted; collect those not stored at all for insert.
        $missing = [];
        foreach ($desired as $identity => $value) {
            if (! isset($stored[$identity])) {
                $missing[] = array_merge(
                    $sourceColumns,
                    [$junctionColumn => $value, 'deleted' => 'n', 'changed_at' => $changedAt]
                );
            } elseif ($stored[$identity]['deleted']) {
                $this->markJunctionRow(
                    $table,
                    array_merge($sourceColumns, [$junctionColumn => $value]),
                    'n',
                    $changedAt
                );
            }
        }

        $this->insertRows($table, $missing);
    }

    /**
     * Set a junction row's `deleted` value  and stamp `changed_at`
     *
     * @param string $table
     * @param array<string, mixed> $columns column => value identifying the row
     * @param string $deleted The `deleted` enum value to write (`'y'` or `'n'`)
     * @param int $changedAt The millisecond timestamp to stamp
     *
     * @return void
     */
    private function markJunctionRow(string $table, array $columns, string $deleted, int $changedAt): void
    {
        $this->db->update(
            $table,
            ['deleted' => $deleted, 'changed_at' => $changedAt],
            $this->createCondition($columns)
        );
    }

    /**
     * Build an equality WHERE condition (`column = ?`) from a column => value map, as ipl-sql expects
     *
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    private function createCondition(array $columns): array
    {
        $condition = [];
        foreach ($columns as $column => $value) {
            $condition[$column . ' = ?'] = $value;
        }

        return $condition;
    }

    /**
     * Insert the given rows into the table, as a single statement
     *
     * @param string $table
     * @param list<array<string, mixed>> $rows Each row must carry the same columns in the same order
     *
     * @return void
     */
    protected function insertRows(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        if (count($rows) === 1) {
            $this->db->insert($table, $rows[0]);

            return;
        }

        $columns = array_keys($rows[0]);

        [$sql, $values] = $this->db->getQueryBuilder()->assembleInsert(
            (new Insert())->into($table)->values($rows[0])
        );

        $tuple = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql .= str_repeat(',' . $tuple, count($rows) - 1);

        foreach (array_slice($rows, 1) as $row) {
            foreach ($columns as $column) {
                $values[] = $row[$column];
            }
        }

        $this->db->prepexec($sql, $values);
    }

    /**
     * Get the model's writable columns as a property => column map
     *
     * Expression columns are omitted as they cannot be written to. The result is memoized in
     * {@see $writableColumnCache} by class, since the column set never changes once a Model
     * class is defined; repeated calls within the same save graph reuse the cached map.
     *
     * @param Model $model
     *
     * @return array<string, string>
     */
    protected function writableColumns(Model $model): array
    {
        $class = get_class($model);
        if (isset($this->writableColumnCache[$class])) {
            return $this->writableColumnCache[$class];
        }

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

        $this->writableColumnCache[$class] = $columns;

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
