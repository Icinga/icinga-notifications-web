<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Model\Source;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;

final class SourceRepository
{
    /** @var string The used password hash algorithm */
    public const HASH_ALGORITHM = PASSWORD_BCRYPT;

    /** @var EntityManager The entity manager instance to use */
    private EntityManager $em;

    /**
     * Create a `sourceRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
        $this->em = new EntityManager($db);
    }

    /**
     * Fetch the source with the given id
     *
     * @param int $id
     *
     * @return ?Source
     */
    public function find(int $id): ?Source
    {
        /** @var ?Source $source */
        $source = Source::on($this->db)
            ->filter(Filter::equal('source.id', $id))
            ->first();

        return $source;
    }

    /**
     * Fetch the source with the given username
     *
     * @param string $username listener_username
     *
     * @return ?Source
     */
    public function findByUsername(string $username): ?Source
    {
        /** @var ?Source $source */
        $source = Source::on($this->db)
            ->filter(Filter::equal('source.listener_username', $username))
            ->first();

        return $source;
    }

    /**
     * Create a new source
     *
     * @param Source $source
     *
     * @return void
     */
    public function create(Source $source): void
    {
        $this->upsert($source);
    }

    /**
     * Update a source
     *
     * @param Source $source
     *
     * @return void
     */
    public function update(Source $source): void
    {
        $this->upsert($source);
    }

    /**
     * Delete a source and dereference it from any rules
     *
     * @param Source $source
     *
     * @return void
     */
    public function delete(Source $source): void
    {
        if ($source->isNew()) {
            // Source has not yet persisted, nothing to do.
            return;
        }

        foreach ($source->rule->columns('id') as $rule) {
            //TODO: add repository for rule
            EventRuleConfigForm::removeRule($this->db, $rule);
        }

        $source->deleted = true;
        $source->listener_username = null;

        $this->em->save($source);
    }

    /**
     * Hash the given password using the configured algorithm
     *
     * @param string $password
     *
     * @return string
     */
    public static function hashPassword(string $password): string
    {
        // Not using PASSWORD_DEFAULT, as the used algorithm should
        // be kept in sync with what the daemon understands
        return password_hash($password, self::HASH_ALGORITHM);
    }

    /**
     * Create or update the given source
     *
     * This method centralizes the shared persistence logic required by both
     * the {@see self::create()} and {@see self::update()} operations to avoid code duplication.
     *
     * @param Source $source The source to create or update.
     */
    private function upsert(Source $source): void
    {
        if (isset($source->listener_password)) {
            try {
                $source->listener_password_hash = self::hashPassword($source->listener_password);
            } finally {
                unset($source->listener_password);
            }
        }

        $this->em->save($source);
    }
}
