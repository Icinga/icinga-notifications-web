<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Countable;
use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use IteratorAggregate;

class Incidents implements IteratorAggregate, Countable
{
    /** @var array<string, ?string> Required tags keyed by name; a null value requires the tag's absence */
    protected array $tags;

    /** @var Connection The database connection to read from */
    protected Connection $db;

    /** @var ?ResultSet The executed query result */
    private ?ResultSet $results = null;

    /**
     * Create new Incidents
     *
     * Matches the incidents of every object that carries each tag given with a value and lacks each tag
     * given as null. Tags not listed are unconstrained. Matching is performed by tag alone, independent of the source.
     *
     * Examples:
     *   ['host' => 'icinga2']                     — host icinga2 and all of its services
     *   ['host' => 'icinga2', 'service' => 'ssh'] — only the ssh service on icinga2
     *   ['host' => 'icinga2', 'service' => null]  — only the host icinga2, none of its services
     *
     * @param array<string, ?string> $tags Required tags keyed by name, a null value requires the tag's absence
     * @param Connection $db The database connection to read from
     */
    public function __construct(array $tags, Connection $db)
    {
        $this->tags = $tags;
        $this->db = $db;
    }

    /**
     * Create new Incidents for the given tags, reading through the default database connection
     *
     * @param array<string, ?string> $tags Required tags keyed by name, a null value requires the tag's absence
     *
     * @return static
     */
    public static function find(array $tags): static
    {
        return new static($tags, Database::get());
    }

    /**
     * Get whether the object has at least one incident
     *
     * @return bool
     */
    public function hasIncident(): bool
    {
        return $this->incidents()->hasResult();
    }

    /**
     * Yield an interaction wrapper for each of the object's incidents
     *
     * @return Generator<Incident>
     */
    public function getIterator(): Generator
    {
        foreach ($this->incidents() as $incident) {
            yield new Incident($incident, $this->db);
        }
    }

    public function count(): int
    {
        return $this->buildQuery()->count();
    }

    private function incidents(): ResultSet
    {
        if ($this->results === null) {
            $this->results = $this->buildQuery()->execute();
        }

        return $this->results;
    }

    private function buildQuery(): Query
    {
        $query = IncidentModel::on($this->db)->filter(Filter::unlike('recovered_at', '*'));

        foreach ($this->tags as $tag => $value) {
            if ($value === null) {
                $query->filter(Filter::unlike("incident.object.tag.$tag", '*'));
            } else {
                $query->filter(Filter::equal("incident.object.tag.$tag", $value));
            }
        }

        return $query;
    }
}
