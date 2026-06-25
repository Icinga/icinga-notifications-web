<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\Sql\Connection;

/**
 * Base class for all module models that tracks the changes made to a model
 *
 * Records which properties have changed since the model was loaded, and whether the model has been
 * persisted yet, so the {@see EntityManager} can store a model and write only what actually changed.
 */
abstract class Model extends \ipl\Orm\Model
{
    /** @var bool Whether this model is newly created and does not yet exist in the database */
    private bool $isNew = true;

    /** @var bool Whether the model is marked for deletion on the next {@see EntityManager::save()} */
    private bool $markedForDeletion = false;

    /** @var array<string, true> Names of properties modified since the model was loaded */
    private array $modifiedProperties = [];

    /**
     * Whether a getter for a Closure-backed property is currently resolving.
     *
     * {@see \ipl\Orm\Common\PropertiesWithDefaults::getProperty()} memoizes a resolved Closure by
     * calling {@see setProperty()}. Without this guard, that internal write would be misread as a
     * user-driven change and (worse) re-enter {@see setProperty()} recursively.
     *
     * @var bool
     */
    private bool $resolvingProperty = false;

    /**
     * Get whether this entity is newly created and does not yet exist in the database
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * Set whether this entity is newly created and does not yet exist in the database
     *
     * @param bool $new
     *
     * @return $this
     */
    public function setNew(bool $new = true): static
    {
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
     */
    public function isModified(?string $property = null): bool
    {
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
     */
    public function getModifiedProperties(): array
    {
        return $this->modifiedProperties;
    }

    /**
     * Reset change tracking and accept the current values as the new baseline
     *
     * @return $this
     */
    public function clearModifiedProperties(): static
    {
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
        return in_array('deleted', $this->getColumns(), true);
    }

    /**
     * Mark the model for deletion on the next {@see EntityManager::save()} and return it
     *
     * If the model uses soft deletes this function must set the `deleted` property
     *
     * @return $this
     */
    public function markDeleted(): static
    {
        $this->markedForDeletion = true;
        if ($this->isSoftDeletable()) {
            $this->deleted = true;
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
     * Get the column used to store the timestamp of the most recent modification to the row
     *
     * `changed_at` is the schema-wide convention, the {@see EntityManager} checks whether the column
     * exists on the model before stamping it.
     *
     * @return string
     */
    public function getChangedAtColumn(): string
    {
        return 'changed_at';
    }

    /**
     * @param string $key The name of the property, which may be a column or a relation
     */
    protected function getProperty(string $key): mixed
    {
        $wasResolving = $this->resolvingProperty;
        $this->resolvingProperty = true;
        try {
            return parent::getProperty($key);
        } finally {
            $this->resolvingProperty = $wasResolving;
        }
    }

    /**
     * @param string $key The name of the property, which may be a column or a relation
     */
    protected function setProperty(string $key, mixed $value): static
    {
        if (! $this->resolvingProperty && ! $this->isNew && ! isset($this->modifiedProperties[$key])) {
            // Resolve the prior value via the trait's iterator, which skips Closure-valued properties.
            // This avoids triggering lazy relation loaders just to capture change-tracking state.
            $hadValue = false;
            $original = null;
            foreach ($this as $k => $v) {
                if ($k === $key) {
                    $hadValue = true;
                    $original = $v;
                    break;
                }
            }

            if (! $hadValue || $original !== $value) {
                $this->modifiedProperties[$key] = true;
            }
        }

        return parent::setProperty($key, $value);
    }

    public static function on(Connection $db): StatefulQuery
    {
        return (new StatefulQuery())
            ->setDb($db)
            ->setModel(new static());
    }
}
