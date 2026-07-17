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
use ipl\Orm\Resolver;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Select;
use PDO;
use RuntimeException;
use SplObjectStorage;

/**
 * Persists models and their related models to the database.
 *
 * The EntityManager is the write-side counterpart to {@see Query}. Whereas a query is bound to a model
 * class and operates on rows matching a filter, the EntityManager operates on concrete {@see Model} instances.
 *
 * ```
 * $em = new EntityManager($db);
 * $em->save($car);                 // INSERT or UPDATE, depending on whether the model is new
 * $em->save($car->delete());       // DELETE or soft-delete, depending on the model
 * ```
 *
 * Whether {@see self::save()} inserts or updates is decided by {@see Model::isNew()}. Updates only write the
 * properties changed since the model was loaded ({@see Model::getModifiedProperties()}). Set relations are cascaded
 * (parents first, then the model, then children and many-to-many links), all within a single transaction.
 *
 * Calling {@see Model::delete()} on a model and passing it to {@see self::save()} will soft or hard delete the
 * model according to whether its table has a {@see Model::DELETED} column.
 *
 * In case a model supports tracking of modification times ({@see Model::getChangedAtColumn()}), the EntityManager
 * will stamp the resulting record with the current time when it is persisted. This is done in a way that phantom
 * reads by the daemon are avoided by locking related rows using a range lock, given the current transaction isolation
 * level is serializable.
 *
 * The optional baseline time and date is used to verify that no related database record exceeds it as if that's
 * the case, it is considered a parallel update that would be overwritten by the save. This is a simple form of
 * optimistic locking, and is used to prevent overwriting changes made by other processes since the data was loaded.
 * This is limited to relation models supporting tracking of modification times. For a base model the caller is
 * responsible to detect conflicts. This is independent of the used driver or transaction isolation level.
 *
 * Limitations:
 * - Saving two separate model instances that map to the same db row will result in the second save overwriting the
 *   changes of the first
 * - {@see self::saveGraph()} does not support cyclic graphs, passing a cyclic graph like:
 *   ```
 *   $parent->children = [$child];
 *   $child->parent = $parent;
 *   $em->save($parent);
 *   ```
 *   will throw a {@see RuntimeException}
 */
class EntityManager
{
    /** @var Connection The database connection to persist to */
    protected Connection $db;

    /** @var ?DateTime Baseline which no relation record must exceed */
    protected ?DateTime $baselineAt;

    /**
     * The models currently being saved by {@see self::saveGraph()}, used to detect cyclic graphs
     *
     * @var SplObjectStorage<Model, null>
     */
    private SplObjectStorage $activeSaves;

    /**
     * Cache of table column maps populated by {@see self::getTableColumnMap()}, keyed by model class
     *
     * @var array<class-string, array<string, true>>
     */
    private array $tableColumnCache = [];

    /**
     * The resolver shared across all models
     *
     * @var Resolver
     */
    private Resolver $resolver;

    /**
     * Create a new EntityManager for the given database connection
     *
     * @param Connection $db
     * @param ?DateTime $baselineAt Baseline which no relation record must exceed
     */
    public function __construct(Connection $db, ?DateTime $baselineAt = null)
    {
        $this->db = $db;
        $this->baselineAt = $baselineAt;
        $this->activeSaves = new SplObjectStorage();
        $this->resolver = (new Query())
            ->setDb($db)
            ->getResolver();
    }

    /**
     * Persist the given model and its set relations
     *
     * @param Model $model
     *
     * @return void
     *
     * @throws RuntimeException
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
     * Delete the given model's row and reset it to a fresh state
     *
     * Soft-deletes when the model's table has a {@see Model::DELETED} column, otherwise hard-deletes
     * the row. Does nothing if the model is new.
     *
     * @param Model $model
     *
     * @return void
     *
     * @throws RuntimeException If the given model has no (complete) primary key value
     */
    protected function delete(Model $model): void
    {
        if ($model->isNew()) {
            return;
        }

        $behaviors = $this->resolver->getBehaviors($model);

        if ($model->isSoftDeletable()) {
            $model->{$model::DELETED} = true;
            $this->persist($model, $behaviors);

            return;
        }

        $condition = $this->createPrimaryKeyCondition($model, $behaviors);
        if ($condition === null) {
            throw new RuntimeException(
                sprintf(
                    'Cannot delete %s without a primary key value',
                    get_class($model)
                )
            );
        }

        $this->ensureOlderThanBaseline($model);
        $this->db->delete($this->db->quoteIdentifier($model->getTableName()), $condition);
        foreach ((array) $model->getKeyName() as $k) {
            unset($model->$k);
        }

        $model->clearModifiedProperties();
        $model->setNew();
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
        if (! $model->isMutable()) {
            throw new RuntimeException(sprintf(
                'setNew() must be called on a model of type %s before saving it',
                get_class($model)
            ));
        }

        if ($this->activeSaves->offsetExists($model)) {
            throw new RuntimeException('Reference loop detected, failed to save');
        }

        $this->activeSaves->offsetSet($model);

        // Snapshot what to cascade before persisting, since persisting resets change tracking. Only
        // explicitly set relations are considered (lazy loaders are closures, skipped by the iterator).
        // A relation is cascaded when the caller (re)assigned it, or when its already-materialized value
        // has pending changes of its own.
        $set = iterator_to_array($model);
        $isNew = $model->isNew();
        $modifiedRelations = $isNew ? [] : $model->getModifiedProperties();

        /** @var array<string, BelongsTo> $dependencies */
        $dependencies = [];
        /** @var array<string, Relation> $children */
        $children = [];
        /** @var array<string, BelongsToMany> $manyToMany */
        $manyToMany = [];
        foreach ($this->resolver->getRelations($model) as $name => $relation) {
            if (
                ! array_key_exists($name, $set)
                || (! $isNew && ! isset($modifiedRelations[$name]) && ! $this->hasPendingChanges($set[$name]))
            ) {
                // The relation has no changes to persist
                continue;
            }

            if ($relation instanceof BelongsTo) {
                $dependencies[$name] = $relation;
            } elseif ($relation instanceof BelongsToMany) {
                $manyToMany[$name] = $relation;
            } else {
                $children[$name] = $relation;
            }
        }

        if ($model->isMarkedForDeletion()) {
            // Delete path
            $this->saveManyToMany($model, $manyToMany, $set);
            $this->saveChildren($model, $children, $set);
            $this->delete($model);
            $this->saveChangedParents($dependencies, $set);
        } else {
            // Insert / Update
            $this->saveParents($model, $dependencies, $set);
            $this->persist($model, $this->resolver->getBehaviors($model));
            $this->saveChildren($model, $children, $set);
            $this->saveManyToMany($model, $manyToMany, $set);
        }

        $this->activeSaves->offsetUnset($model);
    }

    /**
     * Persist the model's BelongsTo parents and copy their keys into the model's foreign keys
     *
     * Parents are persisted before the model so the foreign key on this (source) table points at an
     * existing row. An explicitly assigned `null` clears the foreign key instead.
     *
     * @param Model $model
     * @param array<string, BelongsTo> $dependencies
     * @param array<string, mixed> $set
     *
     * @return void
     */
    private function saveParents(Model $model, array $dependencies, array $set): void
    {
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

            if ($related->isMutable()) {
                $this->saveGraph($related);
            }

            foreach ($relation->determineKeys($model) as $targetColumn => $sourceColumn) {
                $model->$sourceColumn = $related->$targetColumn;
            }
        }
    }

    /**
     * Persist the model's HasOne/HasMany children, copying the model's key into each child's foreign key
     *
     * Children are persisted after the model so its (possibly generated) key is known and can be copied in.
     * When the relation's collection is in replace mode ({@see Collection::sync()}), any stored child no longer
     * in the desired set is removed afterwards via {@see self::reconcileChildren()}. In merge mode, explicitly
     * detached children are deleted.
     *
     * @param Model $model
     * @param array<string, Relation> $children
     * @param array<string, mixed> $set
     *
     * @return void
     */
    private function saveChildren(Model $model, array $children, array $set): void
    {
        foreach ($children as $name => $relation) {
            $keys = $relation->determineKeys($model);
            $value = $set[$name];
            $desired = [];
            foreach ($this->childrenOf($value) as $child) {
                foreach ($keys as $targetColumn => $sourceColumn) {
                    $child->$targetColumn = $model->$sourceColumn;
                }

                $this->saveGraph($child);
                $desired[] = $child;
            }

            if ($value instanceof Collection) {
                if ($value->isReplacing()) {
                    if ($model->isMarkedForDeletion() && ! empty($desired)) {
                        throw new RuntimeException(
                            'A relation of a deleted model in replace mode should not carry attachments'
                        );
                    }

                    $this->reconcileChildren($relation, $model, $keys, $desired);
                } else {
                    foreach ($value->getDetachments() as $child) {
                        $this->delete($child);
                    }
                }

                $value->clearPendingChanges();
            }
        }
    }

    /**
     * Remove the stored children of a HasOne/HasMany relation that are absent from the desired set
     *
     * Only invoked for a collection in replace mode
     *
     * @param Relation $relation The HasOne/HasMany relation being reconciled
     * @param Model $model The source model whose key scopes the children
     * @param array<string, string> $keys The relation's foreign => source key map ({@see Relation::determineKeys()})
     * @param Model[] $desired The children that should remain, already persisted
     *
     * @return void
     */
    private function reconcileChildren(Relation $relation, Model $model, array $keys, array $desired): void
    {
        $targetClass = $relation->getTargetClass();
        $childModel = new $targetClass();
        $childBehaviors = $this->resolver->getBehaviors($childModel);
        $keyColumns = (array) $childModel->getKeyName();

        // Restrict to the children sharing this model's key, persisted with the child's behaviors
        $condition = [];
        foreach ($keys as $foreignColumn => $sourceColumn) {
            $condition[$foreignColumn] = $childBehaviors->persistProperty($model->$sourceColumn, $foreignColumn);
        }

        // The in-memory identities of the children that should remain
        $desiredKeys = [];
        foreach ($desired as $child) {
            $identity = $this->childIdentity($child, $keyColumns);
            if ($identity !== null) {
                $desiredKeys[$identity] = true;
            }
        }

        $isSoftDeletable = $childModel->isSoftDeletable();
        $changedAtColumn = $childModel->getChangedAtColumn();
        $columns = $keyColumns;
        if ($isSoftDeletable) {
            $columns[] = $childModel::DELETED;
        }

        if ($changedAtColumn !== null) {
            $columns[] = $changedAtColumn;
        }

        $select = (new Select())
            ->from($this->db->quoteIdentifier($childModel->getTableName()))
            ->columns(array_map($this->db->quoteIdentifier(...), $columns))
            ->where($this->createCondition($condition));

        foreach ($this->db->select($select)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($isSoftDeletable && $row[$childModel::DELETED] === 'y') {
                continue;
            }

            // Rebuild the stored child in its in-memory form, so its identity is comparable to the desired
            // set and delete() re-persists it identically; then let delete() pick hard vs soft
            $orphan = new $targetClass();
            foreach ($keyColumns as $keyColumn) {
                $orphan->$keyColumn = $childBehaviors->retrieveProperty($row[$keyColumn], $keyColumn);
            }

            if ($changedAtColumn !== null) {
                $orphan->$changedAtColumn = $childBehaviors->retrieveProperty($row[$changedAtColumn], $changedAtColumn);
            }

            $orphan->setNew(false);

            if (! isset($desiredKeys[$this->childIdentity($orphan, $keyColumns)])) {
                $this->delete($orphan);
            }
        }
    }

    /**
     * Build the composite identity of the given child from its in-memory primary key
     *
     * This only serves as helper so {@see self::reconcileChildren()} can tell children apart,
     * it is never handed to the database
     *
     * @param Model $child
     * @param string[] $keyColumns
     *
     * @return ?string
     */
    private function childIdentity(Model $child, array $keyColumns): ?string
    {
        $parts = [];
        foreach ($keyColumns as $keyColumn) {
            if (! $child->hasProperty($keyColumn)) {
                return null;
            }

            $parts[] = (string) $child->$keyColumn;
        }

        return implode("\0", $parts);
    }

    /**
     * Persist the changed BelongsTo parents of a model that is being deleted
     *
     * A parent is independent of the model and outlives it, so an explicit deletion or in-place edit of a
     * parent is still applied — after the model is gone, since the model references the parent. A newly
     * created parent is left untouched ({@see Model::isModified()} is false for it), as persisting it for a
     * model that is being deleted would be meaningless.
     *
     * @param array<string, BelongsTo> $dependencies
     * @param array<string, mixed> $set
     *
     * @return void
     */
    private function saveChangedParents(array $dependencies, array $set): void
    {
        foreach ($dependencies as $name => $relation) {
            $related = $set[$name];
            if ($related instanceof Model && ($related->isMarkedForDeletion() || $related->isModified())) {
                $this->saveGraph($related);
            }
        }
    }

    /**
     * Save the targets of the given many-to-many relations and reconcile each junction
     *
     * @param Model $model The source model
     * @param array<string, BelongsToMany> $manyToMany The many-to-many relations to persist, keyed by name
     * @param array<string, mixed> $set The model's set properties, as snapshotted by {@see self::saveGraph()}
     *
     * @return void
     */
    private function saveManyToMany(Model $model, array $manyToMany, array $set): void
    {
        foreach ($manyToMany as $name => $relation) {
            $collection = $set[$name];
            if (! $collection instanceof Collection) {
                continue;
            }

            $attach = [];
            foreach ($collection->getMembersToSave() as $target) {
                // Models are marked as new when they are hard deleted, so cache the value before saving
                $isDeleted = $target->isMarkedForDeletion();

                if ($target->isMutable()) {
                    $this->saveGraph($target);
                }

                if (! $isDeleted) {
                    $attach[] = $target;
                }
            }

            $this->reconcileJunction(
                $relation,
                $model,
                $attach,
                $collection->getDetachments(),
                $collection->isReplacing()
            );

            $collection->clearPendingChanges();
        }
    }

    /**
     * Get whether the given relation value carries unsaved changes that should be cascaded
     *
     * @param mixed $value A relation value as snapshotted in the set (a model, a collection, or null)
     *
     * @return bool
     */
    private function hasPendingChanges(mixed $value): bool
    {
        if ($value instanceof Collection) {
            return $value->hasPendingChanges();
        }

        if ($value instanceof Model) {
            return $value->isMutable() && ($value->isNew() || $value->isModified() || $value->isMarkedForDeletion());
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
        if (! $model->isNew() && ! $model->isModified()) {
            return;
        }

        $this->ensureOlderThanBaseline($model);

        // Insert case
        if ($model->isNew()) {
            $this->stampChangedAt($model);
            $this->db->insert($this->db->quoteIdentifier($model->getTableName()), $this->extract($model, $behaviors));

            $keyName = $model->getKeyName();
            if (is_string($keyName) && ! $model->hasProperty($keyName)) {
                // Single auto-increment key that wasn't assigned by the application
                $id = $this->db->lastInsertId();
                if ($id !== false) {
                    $model->$keyName = $behaviors->retrieveProperty((int) $id, $keyName);
                }
            }

            $model->clearModifiedProperties();
            $model->setNew(false);

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

        if (empty(array_intersect_key($model->getModifiedProperties(), $this->getTableColumnMap($model)))) {
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
        // the changedAtColumn to the modified set, so re-extract to pick it up.
        $this->stampChangedAt($model);
        $data = $this->extract($model, $behaviors, $model->getModifiedProperties());

        $this->db->update($this->db->quoteIdentifier($model->getTableName()), $data, $condition);

        $model->clearModifiedProperties();
    }

    /**
     * Stamp the model's {@see Model::getChangedAtColumn()} column with the current time if it has one
     *
     * `changed_at` must be strictly increasing, so the stamped value is {@see self::now()} or the current
     * maximum plus one, whichever is greater. Otherwise the daemon would ignore the change.
     *
     * Reading `MAX(changed_at)` under {@see self::$db}'s serializable isolation prevents a phantom read
     *
     * @param Model $model
     *
     * @return void
     */
    protected function stampChangedAt(Model $model): void
    {
        $column = $model->getChangedAtColumn();
        if ($column === null) {
            return;
        }

        $currentMax = $this->db->select(
            (new Select())
                ->from($this->db->quoteIdentifier($model->getTableName()))
                ->columns([$column => new Expression("MAX($column)")])
        )->fetchColumn();

        $next = max($this->now(), (int) $currentMax + 1);
        $model->$column = $this->resolver->getBehaviors($model)->retrieveProperty($next, $column);
    }

    /**
     * Get the current time used for {@see self::stampChangedAt()}
     *
     * @return int
     */
    protected function now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Verify the model has not been changed after {@see self::$baselineAt}
     *
     * A {@see RuntimeException} is thrown if the model's {@see Model::getChangedAtColumn()} is younger than
     * {@see self::$baselineAt}.
     *
     * Does nothing if {@see self::$baselineAt} is null, {@see Model::getChangedAtColumn()} is null
     * or if the model is new.
     *
     * @param Model $model
     *
     * @return void
     *
     * @throws RuntimeException If the model changed after the baseline, or its changed_at was not loaded
     */
    protected function ensureOlderThanBaseline(Model $model): void
    {
        if ($this->baselineAt === null || $model->isNew()) {
            return;
        }

        $column = $model->getChangedAtColumn();
        if ($column === null) {
            return;
        }

        if (! $model->hasProperty($column)) {
            throw new RuntimeException(
                sprintf('Cannot verify baseline: %s was loaded without its %s column', get_class($model), $column)
            );
        }

        if ($model->$column > $this->baselineAt) {
            throw new RuntimeException(
                sprintf('Failed to save: %s has changed after baseline', get_class($model))
            );
        }
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
        $columns = $this->getTableColumnMap($model);
        $data = [];

        // Restrict to the given property set (e.g. the modified set) or fall back to all set properties.
        $properties = $only ?? $model;
        foreach ($properties as $property => $_) {
            if (! isset($columns[$property])) {
                continue;
            }

            $data[$this->db->quoteIdentifier($property)] = $behaviors->persistProperty($model->$property, $property);
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
     * Reconcile the junction rows of a many-to-many relation against the collection's pending changes
     *
     * Missing links are inserted (and soft-deleted ones revived), detached links are removed, and in replace
     * mode any stored link absent from the desired set is removed too. Removal soft-deletes when the junction
     * model has a {@see Model::DELETED} column, otherwise deletes the row.
     *
     * @param BelongsToMany $relation
     * @param Model $source
     * @param Model[] $attach Targets whose links must exist
     * @param Model[] $detach Targets whose links must be removed
     * @param bool $replace Whether to remove stored links absent from $attach
     *
     * @return void
     */
    protected function reconcileJunction(
        BelongsToMany $relation,
        Model $source,
        array $attach,
        array $detach,
        bool $replace
    ): void {
        [$sourceToJunction, $junctionToTarget] = iterator_to_array(
            $relation->setSource($source)->resolve(),
            false
        );

        $sourceBehaviors = $this->resolver->getBehaviors($source);

        $junction = $sourceToJunction[1];
        $sourceColumns = [];
        foreach ($sourceToJunction[2] as $sourceJunctionColumn => $sourceColumn) {
            $sourceColumns[$sourceJunctionColumn] =
                $sourceBehaviors->persistProperty($source->$sourceColumn, $sourceColumn);
        }

        $targetKeys = $junctionToTarget[2];
        $targetColumn = array_key_first($targetKeys);
        $junctionColumn = $targetKeys[$targetColumn];

        $desired = $this->junctionIdentities($attach, $targetColumn);
        $toDetach = $this->junctionIdentities($detach, $targetColumn);

        if ($junction instanceof Model && $junction->isSoftDeletable()) {
            $this->reconcileSoftDeleteJunction(
                $junction,
                $sourceColumns,
                $junctionColumn,
                $desired,
                $toDetach,
                $replace
            );

            return;
        }

        $table = $junction->getTableName();
        $stored = $this->fetchJunctionRows($table, $sourceColumns, $junctionColumn);

        foreach ($desired as $identity => $value) {
            if (! array_key_exists($identity, $stored)) {
                $this->db->insert(
                    $this->db->quoteIdentifier($table),
                    $this->quoteColumns(array_merge($sourceColumns, [$junctionColumn => $value]))
                );
            }
        }

        $removals = $replace ? array_diff_key($stored, $desired) : array_intersect_key($toDetach, $stored);
        foreach ($removals as $value) {
            $this->db->delete(
                $this->db->quoteIdentifier($table),
                $this->createCondition(array_merge($sourceColumns, [$junctionColumn => $value]))
            );
        }
    }

    /**
     * Reconcile a soft-delete junction: insert/revive desired links and mark removed links deleted
     *
     * @param Model $junction A junction model
     * @param array<string, mixed> $sourceColumns
     * @param string $junctionColumn
     * @param array<string, mixed> $desired Target values keyed by identity
     * @param array<string, mixed> $toDetach Target values keyed by identity
     * @param bool $replace
     *
     * @return void
     */
    private function reconcileSoftDeleteJunction(
        Model $junction,
        array $sourceColumns,
        string $junctionColumn,
        array $desired,
        array $toDetach,
        bool $replace
    ): void {
        $behaviors = $this->resolver->getBehaviors($junction);
        $deletedColumn = $junction::DELETED;
        $changedAtColumn = $junction->getChangedAtColumn();
        $keyColumns = (array) $junction->getKeyName();
        $columns = array_unique(array_merge($keyColumns, [$junctionColumn, $deletedColumn]));
        if ($changedAtColumn !== null) {
            $columns[] = $changedAtColumn;
        }

        $select = (new Select())
            ->from($this->db->quoteIdentifier($junction->getTableName()))
            ->columns(array_map($this->db->quoteIdentifier(...), $columns))
            ->where($this->createCondition($sourceColumns));

        $stored = [];
        $storedValues = [];
        $storedChangedAt = [];
        $storedKeys = [];
        foreach ($this->db->select($select)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $identity = (string) $row[$junctionColumn];
            $stored[$identity] = $row[$deletedColumn] === 'y';
            $storedValues[$identity] = $row[$junctionColumn];
            if ($changedAtColumn !== null) {
                $storedChangedAt[$identity] = $row[$changedAtColumn];
            }

            $keyValues = [];
            foreach ($keyColumns as $keyColumn) {
                $keyValues[$keyColumn] = $behaviors->retrieveProperty($row[$keyColumn], $keyColumn);
            }

            $storedKeys[$identity] = $keyValues;
        }

        foreach ($desired as $identity => $value) {
            // In case the link exists, but is marked as deleted, marking the model as not `new` ensures the
            // `delete` flag of the existing row is updated and no insert with an existing PK is attempted
            $isNew = ! isset($stored[$identity]);
            if (! $isNew && ! $stored[$identity]) {
                continue;
            }

            $link = $this->makeJunctionLink(
                get_class($junction),
                $sourceColumns,
                $junctionColumn,
                $value,
                $isNew,
                $storedKeys[$identity] ?? []
            );
            if (! $isNew && $changedAtColumn !== null) {
                $link->$changedAtColumn = $behaviors->retrieveProperty($storedChangedAt[$identity], $changedAtColumn);
            }

            $link->{$deletedColumn} = false;
            $this->persist($link, $behaviors);
        }

        // Of the currently-stored, still-live links, remove those absent from the desired set (replace mode)
        // or explicitly detached (merge mode). Both keep the stored value, so persist() re-derives the same row.
        $toRemove = [];
        foreach ($stored as $identity => $isDeleted) {
            if ($isDeleted) {
                continue;
            }

            $shouldRemove = $replace ? ! isset($desired[$identity]) : isset($toDetach[$identity]);
            if ($shouldRemove) {
                $toRemove[$identity] = $storedValues[$identity];
            }
        }

        foreach ($toRemove as $identity => $value) {
            $link = $this->makeJunctionLink(
                get_class($junction),
                $sourceColumns,
                $junctionColumn,
                $value,
                false,
                $storedKeys[$identity]
            );
            if ($changedAtColumn !== null) {
                $link->$changedAtColumn = $behaviors->retrieveProperty($storedChangedAt[$identity], $changedAtColumn);
            }

            $link->{$deletedColumn} = true;
            $this->persist($link, $behaviors);
        }
    }

    /**
     * Read the identities of the links currently stored for the given source
     *
     * @param string $table
     * @param array<string, mixed> $sourceColumns
     * @param string $junctionColumn The junction's target-side column to read back
     *
     * @return array<string, mixed> The stored target values, keyed by identity
     */
    private function fetchJunctionRows(string $table, array $sourceColumns, string $junctionColumn): array
    {
        $select = (new Select())
            ->from($this->db->quoteIdentifier($table))
            ->columns($this->db->quoteIdentifier($junctionColumn))
            ->where($this->createCondition($sourceColumns));

        $stored = [];
        foreach ($this->db->select($select)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored[(string) $row[$junctionColumn]] = $row[$junctionColumn];
        }

        return $stored;
    }

    /**
     * Build the target identities of the given models keyed by string identity, converting for persistence
     *
     * @param Model[] $targets
     * @param string $targetColumn The target's key column
     *
     * @return array<string, mixed> The persisted target values keyed by identity
     */
    private function junctionIdentities(array $targets, string $targetColumn): array
    {
        $identities = [];
        foreach ($targets as $target) {
            $behaviors = $this->resolver->getBehaviors($target);
            $value = $behaviors->persistProperty($target->$targetColumn, $targetColumn);
            $identities[(string) $value] = $value;
        }

        return $identities;
    }

    /**
     * Build a junction link model for one link, with its source and target key columns set
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $sourceColumns
     * @param string $junctionColumn
     * @param mixed $targetValue
     * @param bool $isNew
     *
     * @return Model
     */
    private function makeJunctionLink(
        string $model,
        array $sourceColumns,
        string $junctionColumn,
        mixed $targetValue,
        bool $isNew,
        array $keyColumns
    ): Model {
        $link = new $model();
        foreach ($sourceColumns as $column => $value) {
            $link->$column = $value;
        }

        $link->$junctionColumn = $targetValue;
        foreach ($keyColumns as $column => $value) {
            $link->$column = $value;
        }

        $link->setNew($isNew);

        return $link;
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
            $condition[$this->db->quoteIdentifier($column) . ' = ?'] = $value;
        }

        return $condition;
    }

    /**
     * Copy a column => value map with its keys quoted with ({@see Connection::quoteIdentifier()})
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function quoteColumns(array $data): array
    {
        $quoted = [];
        foreach ($data as $column => $value) {
            $quoted[$this->db->quoteIdentifier($column)] = $value;
        }

        return $quoted;
    }

    /**
     * Get the model's table columns and cache them per model class
     *
     * @param Model $model
     *
     * @return array<string, true>
     */
    protected function getTableColumnMap(Model $model): array
    {
        $class = get_class($model);
        if (isset($this->tableColumnCache[$class])) {
            return $this->tableColumnCache[$class];
        }

        $columns = array_fill_keys($model->getTableColumns(), true);
        $this->tableColumnCache[$class] = $columns;

        return $columns;
    }

    /**
     * Yield the child models to persist for a HasOne/HasMany relation value
     *
     * @param mixed $value A model (HasOne), a collection (HasMany) or null
     *
     * @return iterable<Model>
     */
    private function childrenOf(mixed $value): iterable
    {
        if ($value instanceof Model) {
            return [$value];
        }

        if ($value instanceof Collection) {
            return $value->getMembersToSave();
        }

        return [];
    }
}
