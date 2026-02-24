<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook\V1;

use ipl\Html\Contract\Form;
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
     * Get available filter targets for event rules
     *
     * If this returns an array of length 1, {@see getRuleFilterEditor()} will immediately be called with the item's
     * key. If the returned array contains multiple values, a simple select box will be rendered first, and the user
     * has to choose an item from the list.
     *
     * @param int $sourceId The ID of the source the rule belongs to
     *
     * @return array<string, string|array<string, string>> target => label | optgroup => (target => label)
     */
    public function getRuleFilterTargets(int $sourceId): array;

    /**
     * Get an editor for the given filter
     *
     * The returned form MUST NOT have a request associated with it and MUST NOT navigate away upon submission.
     * The action of the form is overridden in any case.
     *
     * @param string $filter A filter template or a filter previously serialized by {@see serializeRuleFilter()}
     *
     * @return Form
     */
    public function getRuleFilterEditor(string $filter): Form;

    /**
     * Serialize the filter of the given editor
     *
     * The returned string is stored as-is in the database. The source MUST be able to deserialize it.
     * Upon editing by a user, {@see getRuleFilterEditor()} will be called with the serialized filter.
     *
     * @param Form $editor
     *
     * @return string
     */
    public function serializeRuleFilter(Form $editor): string;
}
