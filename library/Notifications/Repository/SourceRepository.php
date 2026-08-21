<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Source as SourceData;
use Icinga\Module\Notifications\Model\Source;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;

class SourceRepository
{
    /** @var string The used password hash algorithm */
    public const HASH_ALGORITHM = PASSWORD_BCRYPT;

    /**
     * Create a `sourceRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
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
        return Source::on($this->db)
            ->filter(Filter::equal('source.id', $id))
            ->first();
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
        return Source::on($this->db)
            ->filter(Filter::equal('source.listener_username', $username))
            ->first();
    }

    /**
     * Get whether the given source is the only remaining one of its type
     *
     * @param Source $source
     *
     * @return bool
     */
    public function isLastOfItsType(Source $source): bool
    {
        return Source::on($this->db)
            ->columns([new Expression('1')])
            ->filter(
                Filter::all(
                    Filter::equal('type', $source->type),
                    Filter::unequal('id', $source->id)
                )
            )->first() === null;
    }

    /**
     * Create a new source
     *
     * @param SourceData $source
     *
     * @return int The source's ID
     */
    public function create(SourceData $source): int
    {
        return $this->upsert($source, (new Source())->setNew());
    }

    /**
     * Update a source
     *
     * @param SourceData $source
     *
     * @return void
     */
    public function update(SourceData $source): void
    {
        if (! isset($source->id)) {
            throw new InvalidArgumentException('Cannot update a source that does not have an ID');
        }

        $model = $this->find($source->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update a source that does not exist in the database');
        }

        $this->upsert($source, $model);
    }

    /**
     * Delete a source, if it is the last of its type all related rules are deleted as well
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void
    {
        $source = $this->find($id)?->setNew(false);
        if ($source === null) {
            throw new InvalidArgumentException('Cannot delete a source that does not exist in the database');
        }

        $source->client_certificate_subject = null;
        $source->listener_username = null;
        $source->delete();

        if ($this->isLastOfItsType($source)) {
            foreach ($source->rule as $rule) {
                (new EscalationRuleRepository($this->db))->delete($rule->id);
            }
        }

        (new EntityManager($this->db))->save($source);
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
     * @param SourceData $source
     * @param Source $model
     *
     * @return int The source's ID
     */
    private function upsert(SourceData $source, Source $model): int
    {
        $model->type = $source->type;
        $model->name = $source->name;
        $model->listener_username = $source->listenerUsername;
        $model->client_certificate_subject = $source->clientCertificateSubject;
        $model->locked = $source->locked;

        if (isset($source->listenerPassword)) {
            try {
                $model->listener_password_hash = self::hashPassword($source->listenerPassword);
            } finally {
                $source->listenerPassword = null;
            }
        } elseif (isset($source->clientCertificateSubject)) {
            $model->listener_password_hash = null;
        }

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }
}
