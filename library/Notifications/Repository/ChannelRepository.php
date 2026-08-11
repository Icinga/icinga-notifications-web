<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Repository;

use Icinga\Module\Notifications\Common\EntityManager;
use Icinga\Module\Notifications\Form\Data\Channel as ChannelData;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Util\Json;
use InvalidArgumentException;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use Ramsey\Uuid\Uuid;

final class ChannelRepository
{
    /**
     * Create a `ChannelRepository` instance
     *
     * @param Connection $db Database to operate on
     */
    public function __construct(
        private Connection $db
    ) {
    }

    /**
     * Fetch the channel with the given ID
     *
     * @param int $id
     *
     * @return ?Channel
     */
    public function find(int $id): ?Channel
    {
        return Channel::on($this->db)
            ->filter(Filter::equal('id', $id))
            ->first();
    }

    /**
     * Store a new channel
     *
     * @param ChannelData $channel
     *
     * @return int The ID of the given channel
     */
    public function create(ChannelData $channel): int
    {
        $model = (new Channel())->setNew();
        $model->external_uuid = Uuid::uuid4()->toString();

        return $this->upsert($channel, $model);
    }

    /**
     * Update the given channel
     *
     * @param ChannelData $channel
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given channel has no ID or does not exist
     */
    public function update(ChannelData $channel): void
    {
        if (! isset($channel->id)) {
            throw new InvalidArgumentException('Cannot update a channel that does not have an ID');
        }

        $model = $this->find($channel->id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot update a channel that does not exist in the database');
        }

        $this->upsert($channel, $model);
    }

    /**
     * Delete the channel with the given ID
     *
     * The caller is responsible to ensure that the channel is not referenced anymore,
     * neither as a contact's default channel nor in an event rule's escalation.
     *
     * @param int $id
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given channel does not exist
     */
    public function delete(int $id): void
    {
        $model = $this->find($id)?->setNew(false);
        if ($model === null) {
            throw new InvalidArgumentException('Cannot delete a channel that does not exist in the database');
        }

        $model->external_uuid = null;

        (new EntityManager($this->db))->save($model->delete());
    }

    /**
     * Create or update the given channel
     *
     * This method centralizes the shared persistence logic required by both
     * the {@see self::create()} and {@see self::update()} operations to avoid code duplication.
     *
     * @param ChannelData $channel
     * @param Channel $model
     *
     * @return int The channel's ID
     */
    private function upsert(ChannelData $channel, Channel $model): int
    {
        $model->name = $channel->name;
        $model->type = $channel->type;
        $model->config = Json::encode($channel->config, JSON_FORCE_OBJECT);

        (new EntityManager($this->db))->save($model);

        return $model->id;
    }
}
