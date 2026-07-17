<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\Orm\Query;
use LogicException;
use RuntimeException;

/**
 * Base class for all module models that tracks the changes made to a model
 *
 * Records which properties have changed since the model was loaded, and whether the model has been
 * persisted yet, so the {@see EntityManager} can store a model and write only what actually changed.
 *
 * {@see self::setNew()} must be called explicitly when creating a new instance, it tells the {@see EntityManager}
 * whether to insert or update
 */
abstract class Model extends \ipl\Orm\Model
{
    /** @var string The column used for soft deletes */
    public const DELETED = 'deleted';

    /** @var string The column that holds the timestamp of the most recent modification */
    public const CHANGED_AT = 'changed_at';

    /** @var ?bool Whether this model is newly created and does not yet exist in the database */
    private ?bool $isNew = null;

    /** @var bool Whether the model is marked for deletion on the next {@see EntityManager::save()} */
    private bool $markedForDeletion = false;

    /** @var array<string, true> Names of properties modified since the model was loaded */
    private array $modifiedProperties = [];

    /**
     * Get whether this object's properties are mutable
     *
     * @return bool
     */
    public function isMutable(): bool
    {
        return isset($this->isNew);
    }

    /**
     * Get whether this entity is newly created and does not yet exist in the database
     *
     * @return bool
     *
     * @throws RuntimeException In case the model is not mutable
     */
    public function isNew(): bool
    {
        if (! $this->isMutable()) {
            throw new RuntimeException('Model is not mutable');
        }

        return $this->isNew;
    }

    /**
     * Set whether this entity is newly created and does not yet exist in the database
     *
     * @param bool $new
     *
     * @return $this
     *
     * @throws LogicException In case the model is marked for deletion
     */
    public function setNew(bool $new = true): static
    {
        if ($this->isMarkedForDeletion()) {
            throw new LogicException('Model is marked for deletion');
        }

        $this->isNew = $new;

        return $this;
    }

    /**
     * Get whether the entity, or the given property, has unsaved modifications
     *
     * Always returns false for new entities, which carry no change tracking.
     *
     * @param ?string $property The property to check, or null to check the whole entity
     *
     * @return bool
     *
     * @throws RuntimeException In case the model is not mutable
     */
    public function isModified(?string $property = null): bool
    {
        if (! $this->isMutable()) {
            throw new RuntimeException('Model is not mutable');
        }

        if ($property === null) {
            return ! empty($this->modifiedProperties);
        }

        return isset($this->modifiedProperties[$property]);
    }

    /**
     * Get the names of all properties modified since the entity was loaded as a set keyed by name
     *
     * The keys may be columns or relations.
     *
     * @return array<string, true>
     *
     * @throws RuntimeException In case the model is not mutable
     */
    public function getModifiedProperties(): array
    {
        if (! $this->isMutable()) {
            throw new RuntimeException('Model is not mutable');
        }

        return $this->modifiedProperties;
    }

    /**
     * Reset change tracking and accept the current values as the new baseline
     *
     * @return $this
     *
     * @throws RuntimeException In case the model is not mutable
     */
    public function clearModifiedProperties(): static
    {
        if (! $this->isMutable()) {
            throw new RuntimeException('Model is not mutable');
        }

        $this->modifiedProperties = [];
        $this->markedForDeletion = false;

        return $this;
    }

    /**
     * Get whether the model's table uses soft deletes
     *
     * @return bool
     */
    public function isSoftDeletable(): bool
    {
        return in_array(static::DELETED, $this->getTableColumns(), true);
    }

    /**
     * Mark the model for deletion on the next {@see EntityManager::save()} and return it
     *
     * If the model uses soft deletes this function must set the {@see static::DELETED} property
     *
     * @return $this
     *
     * @throws LogicException In case the model is new
     */
    public function delete(): static
    {
        if ($this->isNew()) {
            throw new LogicException('Model is marked as new and cannot be deleted');
        }

        $this->markedForDeletion = true;
        if ($this->isSoftDeletable()) {
            $this->{static::DELETED} = true;
        }

        return $this;
    }

    /**
     * Get whether the model is marked for deletion on the next {@see EntityManager::save()}
     *
     * @return bool
     */
    public function isMarkedForDeletion(): bool
    {
        return $this->markedForDeletion;
    }

    /**
     * Get all columns of the model's table, including the primary key
     *
     * @return string[]
     */
    public function getTableColumns(): array
    {
        return array_merge((array) $this->getKeyName(), $this->getColumns());
    }

    /**
     * Get the column used to store the timestamp of the most recent modification to the row
     *
     * `changed_at` is the schema-wide convention, returns null if the model has no such column
     *
     * @return ?string
     */
    public function getChangedAtColumn(): ?string
    {
        return in_array(static::CHANGED_AT, $this->getTableColumns(), true) ? static::CHANGED_AT : null;
    }

    protected function setProperty(string $key, mixed $value): static
    {
        if ($this->isMutable()) {
            $original = null;
            if ($this->hasProperty($key)) {
                if ($value instanceof Query) {
                    // Queries are accepted since the only justifiable reason
                    // to expect them is when a closure is being resolved
                    if (! $this->isAToOneRelation($value, $key)) {
                        $value = Collection::fromLoaded($value);
                    }

                    return parent::setProperty($key, $value);
                }

                $original = $this->getProperty($key);
                if ($original instanceof Collection) {
                    // Interpret assignments to a collection as synchronization
                    $original->sync($value);

                    return $this;
                }
            }

            if (! $this->isNew() && ! isset($this->modifiedProperties[$key]) && $value !== $original) {
                $this->modifiedProperties[$key] = true;
            }
        }

        return parent::setProperty($key, $value);
    }

    /**
     * Get whether the given relation is a to-one relation
     *
     * In the far future this should be easier to detect as the model
     * should not be responsible for collection transformation anymore.
     *
     * @param Query $query
     * @param string $relationName
     *
     * @return bool
     */
    private function isAToOneRelation(Query $query, string $relationName): bool
    {
        $relations = $query->getResolver()->getRelations($this);

        return $relations->has($relationName) && $relations->get($relationName)->isOne();
    }
}
