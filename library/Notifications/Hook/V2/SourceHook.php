<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook\V2;

use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Widget\Icon;
use Traversable;

interface SourceHook
{
    /**
     * Get the type of source this integration is responsible for
     *
     * @return string
     */
    public function getSourceType(): string;

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
     * Get whether the condition is valid
     *
     * @param Condition $condition
     *
     * @return bool
     */
    public function isValidCondition(Condition $condition): bool;

    /**
     * Enrich the given condition with metadata like the columnLabel
     *
     * @param Condition $condition
     *
     * @return void
     */
    public function enrichCondition(Condition $condition): void;

    /**
     * Get all jsonPaths for the condition's column
     *
     * @param Condition $condition
     *
     * @return array<string>
     */
    public function getJsonPaths(Condition $condition): array;

    /**
     * Get suggestions for a value field
     *
     * @param string $column
     * @param string $searchTerm
     * @param Chain $searchFilter
     *
     * @return Traversable Values to be suggested as `search` => `label`
     */
    public function getValueSuggestions(string $column, string $searchTerm, Chain $searchFilter): Traversable;

    /**
     * Get suggestions for a column field
     *
     * @param string $searchTerm
     *
     * @return Traversable Columns to be suggested as `search` => `label`
     */
    public function getColumnSuggestions(string $searchTerm): Traversable;

    /**
     * Get whether a relation label should be added to the suggestion
     *
     * @param string $column
     *
     * @return bool
     */
    public function shouldShowRelationFor(string $column): bool;
}
