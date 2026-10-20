<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook\V2;

use InvalidArgumentException;
use ipl\Stdlib\Filter\Chain;

interface V1FilterMigrationHook
{
    /**
     * Get the type of source this hook is responsible for
     *
     * @return string
     */
    public function getSourceType(): string;

    /**
     * Migrate the filter serialized by {@see \Icinga\Module\Notifications\Hook\V1\SourceHook::serializeRuleFilter()}
     *
     * The result SHOULD only contain conditions that are valid according to {@see SourceHook::assertValidCondition()}.
     * However, the user is able to review the result and any changes are validated as usual by the {@see SourceHook}.
     *
     * @param string $v1Filter
     *
     * @return Chain
     * @throws InvalidArgumentException If the migration is not possible, its message is shown to the user and logged.
     */
    public function migrateFilter(string $v1Filter): Chain;
}
