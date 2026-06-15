<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Source as SourceModel;
use Icinga\Module\Notifications\Repository\SourceRepository;
use ipl\Sql\Connection;
use RuntimeException;

/**
 * Utility class for integrations to manage their sources
 *
 * This class allows modifying the underlying source using the setter methods.
 *
 * Changes are in memory until persisted by {@see self::save()}.
 */
final class Source
{
    /**
     * Create a new source-managing instance
     *
     * This allows modifying the underlying source using the setter methods.
     *
     * @param SourceModel $source The source to work with
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private SourceModel $source,
        private Connection $db
    ) {
    }

    /**
     * Get a source-managing instance with the given username.
     *
     * If the source does not exist in the database, a new source is created.
     * To store the source in the database, {@see self::save()} must be called.
     *
     * @param string $username
     *
     * @return self
     */
    public static function get(string $username): self
    {
        $source = (new SourceRepository(Database::get()))
            ->findByUsername($username);

        if ($source === null) {
            $source = new SourceModel();
            $source->listener_username = $username;
        }

        return new self($source, Database::get());
    }

    /**
     * Get the name of the source
     *
     * @return ?string
     */
    public function getName(): ?string
    {
        return $this->source->name ?? null;
    }

    /**
     * Set the name of the source
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName(string $name): self
    {
        $this->source->name = $name;

        return $this;
    }

    /**
     * Set the type of the source
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType(string $type): self
    {
        $this->source->type = $type;

        return $this;
    }

    /**
     * Set the password for the source
     *
     * @param string $password
     *
     * @return $this
     */
    public function setPassword(string $password): self
    {
        $this->source->listener_password = $password;

        return $this;
    }

    /**
     * Save the source
     *
     * This makes sure the source is locked.
     *
     * @return void
     *
     * @throws RuntimeException If the source name or type is not set
     */
    public function save(): void
    {
        if ($this->source->isNew() && (! isset($this->source->name) || ! isset($this->source->type))) {
            throw new RuntimeException('Source must have a name and type');
        }

        $this->source->locked = true;

        $this->db->transaction(function () {
            $this->source->isNew()
                ? (new SourceRepository($this->db))->create($this->source)
                : (new SourceRepository($this->db))->update($this->source);
        });
    }

    /**
     * Delete the source
     *
     * This will also dereference it from any rules
     *
     * @return void
     */
    public function delete(): void
    {
        $this->db->transaction(fn () => (new SourceRepository($this->db))->delete($this->source));
    }
}
