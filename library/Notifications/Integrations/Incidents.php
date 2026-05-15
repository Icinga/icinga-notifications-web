<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use InvalidArgumentException;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Stdlib\Filter;
use IteratorAggregate;

class Incidents implements IteratorAggregate
{
    /** @var string Hex-encoded SHA256 hash of the identifying source/tags */
    protected string $objectId;

    protected ?ResultSet $resultSet = null;

    /**
     * Create new Incidents
     *
     * @param int $sourceId The id of the source that owns the object
     * @param array<string, string> $tags The complete identifying tags of the object
     */
    public function __construct(int $sourceId, array $tags)
    {
        $this->objectId = self::objectId($sourceId, $tags);
    }

    public function hasIncident(): bool
    {
        return $this->incidents()->hasResult();
    }

    /**
     * @return Generator<int, Incident>
     */
    public function getIterator(): Generator
    {
        foreach ($this->incidents() as $incident) {
            yield new Incident($incident);
        }
    }

    protected function incidents(): ResultSet
    {
        if ($this->resultSet === null) {
            $this->resultSet = $this->buildQuery()->execute();
        }

        return $this->resultSet;
    }

    protected function buildQuery(): Query
    {
        return IncidentModel::on(Database::get())
            ->with('object')
            ->filter(Filter::equal('object_id', $this->objectId));
    }

    /**
     * Compute the object id for a given source and tags
     *
     * Mirrors the daemon's ID function in icinga-notifications/internal/object/object.go so the
     * resulting hash matches the value stored in object.id.
     *
     * @param int $sourceId
     * @param array<string, string> $tags
     *
     * @return string
     */
    public static function objectId(int $sourceId, array $tags): string
    {
        if ($sourceId < 0) {
            throw new InvalidArgumentException(sprintf('source id %d is negative', $sourceId));
        }

        $payload = pack('J', $sourceId);

        ksort($tags);

        // A minor bug in the daemon adds these bytes, but fixing it would break all existing object_id's
        // so we reproduce it here. See: https://github.com/Icinga/icinga-notifications/issues/421
        $payload .= str_repeat("\0\0", count($tags));

        foreach ($tags as $key => $value) {
            $payload .= $key . "\0" . $value . "\0";
        }

        return hash('sha256', $payload);
    }
}
