<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\Sql\Connection;

/**
 * Base class for all Models of the module, properties of instances may be edited and can be persisted by
 * passing them to the EntityManager
 */
abstract class Model extends \ipl\Orm\Model
{
    /** @var bool Whether this model is new, i.e. not yet persisted to the database */
    private bool $isNew = true;

    /** @var array<string, true> Names of properties changed since the model was loaded */
    private array $dirtyProperties = [];

    /** @var array<string, mixed> Original values of changed properties, indexed by property name */
    private array $originalValues = [];

    /**
     * Get whether this entity is new, i.e. not yet persisted to the database
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->isNew;
    }

    /**
     * Set whether this entity is new, i.e. not yet persisted to the database
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
     * Get whether the entity, or the given property, has unsaved changes
     *
     * Always returns false for new entities, which carry no change tracking.
     *
     * @param ?string $property The property to check, or null to check the whole entity
     *
     * @return bool
     */
    public function isDirty(?string $property = null): bool
    {
        if ($property === null) {
            return ! empty($this->dirtyProperties);
        }

        return isset($this->dirtyProperties[$property]);
    }

    /**
     * Get the names of all properties changed since the entity was loaded
     *
     * @return string[]
     */
    public function getDirty(): array
    {
        return array_keys($this->dirtyProperties);
    }

    /**
     * Get the original (pre-change) value of the given property
     *
     * Returns the current value if the property has not been changed.
     *
     * @param string $property
     *
     * @return mixed
     */
    public function getOriginal(string $property): mixed
    {
        if (array_key_exists($property, $this->originalValues)) {
            return $this->originalValues[$property];
        }

        return $this->getProperty($property);
    }

    /**
     * Reset change tracking and accept the current values as the new baseline
     *
     * @return $this
     */
    public function markClean(): static
    {
        $this->dirtyProperties = [];
        $this->originalValues = [];

        return $this;
    }

    protected function setProperty(string $key, mixed $value): static
    {
        if (! $this->isNew && ! isset($this->dirtyProperties[$key])) {
            $original = $this->hasProperty($key) ? $this->offsetGet($key) : null;
            if ($original !== $value) {
                $this->originalValues[$key] = $original;
                $this->dirtyProperties[$key] = true;
            }
        }

        return parent::setProperty($key, $value);
    }

    public static function on(Connection $db)
    {
        return new StatefulQuery()
            ->setDb($db)
            ->setModel(new static());
    }
}