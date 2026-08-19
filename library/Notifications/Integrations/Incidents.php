<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Integrations;

use Countable;
use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Incident as IncidentModel;
use Icinga\Module\Notifications\Model\ObjectIdTag;
use Icinga\Module\Notifications\Repository\ContactRepository;
use Icinga\User;
use ipl\Orm\Query;
use ipl\Orm\ResultSet;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Filter\NotExists;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Any;
use IteratorAggregate;

use function ipl\Stdlib\iterable_value_first;

/**
 * @implements IteratorAggregate<int, Incident>
 */
class Incidents implements IteratorAggregate, Countable
{
    /** @var iterable<array<string, ?string>> Sets of tags, keyed by tag name, of which an object has to satisfy any */
    protected iterable $tagSets;

    /** @var Connection The database connection to read from */
    protected Connection $db;

    /** @var ?ResultSet The executed query result */
    private ?ResultSet $results = null;

    /** @var ?Any The filter matching any of the tag sets */
    private ?Any $tagSetFilter = null;

    /** @var bool Whether an object may have id tags other than those given in the set */
    private bool $allowPartialMatches;

    /**
     * Create new Incidents
     *
     * Matches the incidents of every object that matches any of the given tag sets.
     *
     * @param iterable<array<string, ?string>> $tagSets tag sets of which an object
     * @param Connection $db The database connection to read from
     * @param bool $allowPartialMatches Whether an object may have id tags other than those given in the set
     */
    public function __construct(iterable $tagSets, Connection $db, bool $allowPartialMatches)
    {
        $this->tagSets = $tagSets;
        $this->db = $db;
        $this->allowPartialMatches = $allowPartialMatches;
    }

    /**
     * Get the incident of the object matching the given set of id tags, reading through the default database connection
     *
     * @param array<string, string> $tags The full set of id tags
     *
     * @return ?Incident
     */
    public static function get(array $tags): ?Incident
    {
        // TODO: Once object_id no longer depends on the source_id, object_id should be used to optimize this
        return iterable_value_first(new static([$tags], Database::get(), false));
    }

    /**
     * Get the matching incident for each given set of id tags
     *
     * @param iterable<array<string, string>> $tagSets
     *
     * @return static
     */
    public static function getAll(iterable $tagSets): static
    {
        // TODO: Once object_id no longer depends on the source_id, object_id should be used to optimize this
        return new static($tagSets, Database::get(), false);
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
     */
    public static function matchAll(iterable $tagSets): static
    {
        return new static($tagSets, Database::get(), true);
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
     * Get whether the object has at least one incident
     *
     * @return bool
     */
    public function hasIncident(): bool
    {
        if ($this->results !== null) {
            return $this->incidents()->hasResult();
        }

        return $this->buildQuery()
            ->columns([new Expression('1')])
            ->first() !== null;
    }

    /**
     * Get the given user's current incident roles
     *
     * Returns a generator that yields {@see Incident} as key and the user's role as string.
     * The role can either be 'manager', 'recipient' or 'subscriber'.
     *
     * @param User $user
     *
     * @return Generator<mixed, Incident, 'manager|recipient|subscriber', void>
     * @phpstan-return Generator<Incident, 'manager|recipient|subscriber', mixed, void>
     */
    public function getRoles(User $user): Generator
    {
        $filter = Filter::equal('incident_contact.contact.username', $user->getUsername());
        $filter->metaData()->set('forceOptimization', false);

        $incidents = $this->buildQuery()
            ->withColumns(['role' => 'incident_contact.role'])
            ->filter($filter);
        foreach ($incidents as $incident) {
            yield new Incident($incident, $this->db) => $incident->role;
        }
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
            yield new Incident($incident, $this->db);
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
            $this->results = $this->buildQuery()->execute();
        }

        return $this->results;
    }

    private function buildQuery(): Query
    {
        return IncidentModel::on($this->db)
            ->filter(Filter::unlike('recovered_at', '*'))
            ->filter($this->tagSetFilter());
    }

    /**
     * Get the filter matching the incidents of any of the tag sets
     */
    private function tagSetFilter(): Any
    {
        if ($this->tagSetFilter === null) {
            $this->tagSetFilter = Filter::any();
            foreach ($this->tagSets as $tags) {
                $tagFilter = Filter::all();
                foreach ($tags as $tag => $value) {
                    if ($value === null) {
                        $tagFilter->add(Filter::unlike("incident.object.tag.$tag", '*'));
                    } else {
                        $tagFilter->add(Filter::equal("incident.object.tag.$tag", $value));
                    }
                }

                // TODO: This is a workaround to satisfy the contract of get() and getAll(), remove this and the helper
                //       once object_ids can be used
                if (! $this->allowPartialMatches) {
                    $tagFilter->add($this->hasNoOtherTagsThan(array_keys($tags)));
                }

                $this->tagSetFilter->add($tagFilter);
            }

            if ($this->tagSetFilter->isEmpty()) {
                // If no tag sets were provided, this ensures no incidents are returned
                $this->tagSetFilter->add(Filter::unlike('id', '*'));
            }
        }

        return $this->tagSetFilter;
    }

    private function hasNoOtherTagsThan(array $tags): Filter\Rule
    {
        $otherTags = ObjectIdTag::on($this->db)
            ->columns([new Expression('1')])
            ->filter(Filter::unequal('tag', $tags));

        return new NotExists(
            $otherTags->assembleSelect()->where(sprintf(
                '%s.object_id = %s.object_id',
                (new ObjectIdTag())->getTableAlias(),
                (new IncidentModel())->getTableAlias()
            ))
        );
    }
}
