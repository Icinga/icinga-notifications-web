<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook\V2;

use ipl\Stdlib\Filter\Condition;
use ipl\Web\Control\SearchBar\Suggestions;
use ipl\Web\Widget\Icon;

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
     * Get the JsonPath metadata for a condition
     *
     * @param Condition $condition
     *
     * @return string
     */
    public function getJsonPath(Condition $condition): string;

    /**
     * Get the Suggestions for the editor
     *
     * @return Suggestions
     */
    public function getSuggestions(): Suggestions;

    /**
     * Get the metadata keys for the conditions
     *
     * Only the given keys will be added to the SearchEditor and stored in the database
     *
     * @return string[]
     */
    public function getMetadataKeys(): array;
}
