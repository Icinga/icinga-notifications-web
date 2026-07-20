<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Closure;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Form\Data\Source as SourceData;
use Icinga\Module\Notifications\Repository\SourceRepository;
use RuntimeException;

/**
 * Utility class for integrations to manage their sources
 *
 * Changes are in memory until persisted by {@see self::save()}.
 */
final class Source
{
    /** @var ?string The plain text listener password */
    private ?string $listenerPassword = null;

    /**
     * Create a new source-managing instance
     *
     * @param Closure $transactionWrapper Callback to execute a transaction
     * @param SourceRepository $repository Repository to work with
     * @param string $listenerUsername The source's listener username
     * @param ?int $id The source ID, NULL for a new source
     * @param ?string $type The source type, NULL for a new source
     * @param ?string $name The source name, NULL for a new source
     */
    public function __construct(
        private Closure $transactionWrapper,
        private SourceRepository $repository,
        private string $listenerUsername,
        private ?int $id,
        private ?string $type,
        private ?string $name
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
        $db = Database::get();
        $repo = new SourceRepository($db);
        $source = $repo->findByUsername($username);

        return new self(
            $db->transaction(...),
            $repo,
            $username,
            $source->id ?? null,
            $source->type ?? null,
            $source->name ?? null
        );
    }

    /**
     * Get the name of the source
     *
     * @return ?string
     */
    public function getName(): ?string
    {
        return $this->name ?? null;
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
        $this->name = $name;

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
        $this->type = $type;

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
        $this->listenerPassword = $password;

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
        if (! isset($this->name) || ! isset($this->type)) {
            throw new RuntimeException('Source must have a name and type');
        }

        $source = new SourceData(
            id: $this->id,
            type: $this->type,
            name: $this->name,
            listenerUsername: $this->listenerUsername,
            listenerPassword: $this->listenerPassword,
            clientCertificateSubject: null,
            locked: true
        );

        // Only store plain text passwords until no longer necessary
        $this->listenerPassword = null;

        if (isset($source->id)) {
            $this->wrapInTransaction(fn() => $this->repository->update($source));
        } else {
            $this->id = $this->wrapInTransaction(fn() => $this->repository->create($source));
        }
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
        if (! isset($this->id)) {
            return;
        }

        $this->wrapInTransaction(fn() => $this->repository->delete($this->id));
    }

    /**
     * Wrap and call the given callable in an active database transaction
     *
     * @param callable $callback
     *
     * @return mixed The callable's return value, or false on error
     */
    private function wrapInTransaction(callable $callback): mixed
    {
        return call_user_func($this->transactionWrapper, $callback);
    }
}
