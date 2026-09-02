<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Countable;
use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Repository\ContactRepository;
use Icinga\User;
use InvalidArgumentException;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Any;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, Incident>
 */
class Incidents implements IteratorAggregate, Countable
{
    /** @var ?ResultSet<IncidentModel> The executed query result */
    private ?ResultSet $results = null;

    /** @var Query<IncidentModel> The query to get the matching incidents */
    private Query $query;

    /**
     * Create new Incidents from the given query
     *
     * The query is used as is, if it is intended to be used otherwise it should be cloned before passing it.
     *
     * @param Query<IncidentModel> $query
     */
    public function __construct(Query $query)
    {
        $this->query = $query;
    }

    /**
     * Get the incident of the object matching the given set of id tags, reading through the default database connection
     *
     * If no matching incident is found, calling any function on the returned instance throws an
     * {@see IncidentNotFoundException}
     *
     * @param array<string, string> $tags The full set of id tags
     *
     * @return Incident
     *
     * @throws InvalidArgumentException If $tags is empty
     */
    public static function get(array $tags): Incident
    {
        return Incident::fromQuery(
            static::openIncidents(Database::get())->filter(static::tagSetFilterExactMatches([$tags]))
        );
    }

    /**
     * Get the matching incident for each given set of id tags
     *
     * @param iterable<array<string, string>> $tagSets
     *
     * @return static
     *
     * @throws InvalidArgumentException If $tagSets or one of its elements is empty
     */
    public static function getAll(iterable $tagSets): static
    {
        return new static(static::openIncidents(Database::get())->filter(static::tagSetFilterExactMatches($tagSets)));
    }

    /**
     * Get the incidents of all objects matching any of the given tag sets
     *
     * An absent tag matches any value.
     *
     * Using null as a value requires the tag to be absent.
     *
     * If all given sets are guaranteed to contain the full id tags of each object use {@see static::getAll()} instead!
     *
     * Examples:
     *   [['host' => 'icinga2']]                     — host icinga2 and all of its services
     *   [['host' => 'icinga2', 'service' => 'ssh']] — only the ssh service on icinga2
     *   [['host' => 'icinga2', 'service' => null]]  — only the host icinga2, none of its services
     *   [['host' => 'a'], ['host' => 'b']]          — the hosts a and b and all of their services
     *
     * @param iterable<array<string, ?string>> $tagSets Tag sets
     *
     * @return static
     *
     * @throws InvalidArgumentException If $tagSets or one of its elements is empty
     */
    public static function matchAll(iterable $tagSets): static
    {
        return new static(static::openIncidents(Database::get())->filter(static::tagSetFilterPartialMatches($tagSets)));
    }

    /**
     * Get whether the given user can manage incidents
     *
     * @param User $user
     *
     * @return bool
     */
    public static function canManage(User $user): bool
    {
        return (new ContactRepository(Database::get()))->findByUsername($user->getUsername()) !== null;
    }

    /**
     * Get whether the given user can subscribe to incidents
     *
     * @param User $user
     *
     * @return bool
     */
    public static function canSubscribe(User $user): bool
    {
        return (new ContactRepository(Database::get()))->findByUsername($user->getUsername()) !== null;
    }

    /**
     * Yield an interaction wrapper for each of the object's incidents
     *
     * @return Generator<mixed, mixed, Incident, void>
     * @phpstan-return Generator<mixed, Incident, mixed, void>
     */
    public function getIterator(): Generator
    {
        foreach ($this->incidents() as $incident) {
            yield Incident::fromModel($incident, $this->query->getDb());
        }
    }

    public function count(): int
    {
        return $this->incidents()->count();
    }

    /**
     * @return ResultSet<IncidentModel>
     */
    private function incidents(): ResultSet
    {
        if ($this->results === null) {
            $this->results = $this->query->execute();
        }

        return $this->results;
    }

    /**
     * @return Query<IncidentModel>
     */
    private static function openIncidents(Connection $db): Query
    {
        return IncidentModel::on($db)->filter(Filter::unlike('recovered_at', '*'));
    }

    /**
     * @param iterable<array<string, string>> $tagSets
     */
    private static function tagSetFilterExactMatches(iterable $tagSets): Any
    {
        $tagSetFilter = new Any();
        foreach ($tagSets as $tags) {
            if (empty($tags)) {
                throw new InvalidArgumentException('A set of id tags must not be empty');
            }

            $tagSetFilter->add(Filter::equal('object_id', static::objectIdFor($tags)));
        }

        if ($tagSetFilter->isEmpty()) {
            throw new InvalidArgumentException('At least one set of id tags is required');
        }

        return $tagSetFilter;
    }

    /**
     * @param iterable<array<string, ?string>> $tagSets
     */
    private static function tagSetFilterPartialMatches(iterable $tagSets): Any
    {
        $tagSetFilter = new Any();
        foreach ($tagSets as $tags) {
            if (empty($tags)) {
                throw new InvalidArgumentException('A set of id tags must not be empty');
            }

            $tagFilter = Filter::all();
            foreach ($tags as $tag => $value) {
                if ($value === null) {
                    $tagFilter->add(Filter::unlike("incident.object.tag.$tag", '*'));
                } else {
                    $tagFilter->add(Filter::equal("incident.object.tag.$tag", $value));
                }
            }

            $tagSetFilter->add($tagFilter);
        }

        if ($tagSetFilter->isEmpty()) {
            throw new InvalidArgumentException('At least one set of id tags is required');
        }

        return $tagSetFilter;
    }

    /**
     * Get the object id for the given id tags
     *
     * @param array<string, string> $tags
     *
     * @return string
     */
    private static function objectIdFor(array $tags): string
    {
        ksort($tags, SORT_STRING);
        $data = '';
        foreach ($tags as $tag => $value) {
            $data .= $tag . chr(0) . $value . chr(0);
        }

        return hash('sha256', $data);
    }
}
