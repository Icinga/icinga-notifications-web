<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook\V2;

use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\Widget\Icon;
use Traversable;

interface SourceHook
{
    /**
     * Get the label of the source this integration is responsible for
     *
     * @return string
     */
    public function getSourceLabel(): string;

    /**
     * Get the icon of the source this integration is responsible for
     *
     * @return Icon
     */
    public function getSourceIcon(): Icon;

    /**
     * Assert that the given condition is valid
     *
     * Implementations must throw a {@see SearchException} carrying a user-facing
     * message if the condition is not valid.
     *
     * @param Condition $condition
     *
     * @return void
     *
     * @throws SearchException If the condition is not valid
     */
    public function assertValidCondition(Condition $condition): void;

    /**
     * Enrich the given condition with metadata like the columnLabel
     *
     * @param Condition $condition
     *
     * @return void
     */
    public function enrichCondition(Condition $condition): void;

    /**
     * Get all JsonPaths for all given columns, keyed by the column
     *
     * @param string ...$columns
     *
     * @return array<string, string[]>
     */
    public function getJsonPaths(string ...$columns): array;

    /**
     * Get suggestions for a value field
     *
     * @param string $column
     * @param string $searchTerm
     * @param Chain $searchFilter
     *
     * @return Traversable Provider for {@see SearchSuggestions::__construct}
     */
    public function getValueSuggestions(string $column, string $searchTerm, Chain $searchFilter): Traversable;

    /**
     * Get suggestions for a column field
     *
     * @param string $searchTerm
     *
     * @return Traversable Provider for {@see SearchSuggestions::__construct}
     */
    public function getColumnSuggestions(string $searchTerm): Traversable;
}
