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
     * Get the names of all properties changed since the entity was loaded as a set keyed by name
     *
     * @return array<string, true>
     */
    public function getDirtyMap(): array
    {
        return $this->dirtyProperties;
    }

    /**
     * Reset change tracking and accept the current values as the new baseline
     *
     * @return $this
     */
    public function markClean(): static
    {
        $this->dirtyProperties = [];

        return $this;
    }

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

    protected function setProperty(string $key, mixed $value): static
    {
        if (! $this->resolvingProperty && ! $this->isNew && ! isset($this->dirtyProperties[$key])) {
            // Resolve the prior value via the trait's iterator, which skips Closure-valued properties.
            // This avoids triggering lazy relation loaders just to capture dirty-tracking state.
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
                $this->dirtyProperties[$key] = true;
            }
        }

        return parent::setProperty($key, $value);
    }

    public static function on(Connection $db)
    {
        return (new StatefulQuery())
            ->setDb($db)
            ->setModel(new static());
    }
}
