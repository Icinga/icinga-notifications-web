<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Util;

use BackedEnum;
use DateTime;
use DateTimeInterface;
use ipl\Orm\Behaviors;
use ipl\Sql\Connection;
use ipl\Sql\Cursor;
use ipl\Sql\Select;
use Ramsey\Uuid\UuidInterface;

class ObjectSuggestionsCursor extends Cursor
{
    /** @var Behaviors The registered behaviors of the model which we're going to yield results for. */
    protected Behaviors $behaviors;

    /** @var string The actual property name of the model we're going to yield results for. */
    protected string $column;

    public function __construct(Connection $db, Select $select, Behaviors $behaviors, string $column)
    {
        parent::__construct($db, $select);

        $this->behaviors = $behaviors;
        $this->column = $column;
    }

    public function getIterator(): \Traversable
    {
        foreach (parent::getIterator() as $key => $value) {
            $value = $this->behaviors->retrieveProperty($value, $this->column);
            if ($value instanceof DateTime) {
                // The search bar can't handle date time objects, so convert it back to milliseconds again.
                $value = $value->format(DateTimeInterface::RFC3339_EXTENDED);
            } elseif ($value instanceof UuidInterface) {
                $value = (string) $value;
            } elseif ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif (is_bool($value)) {
                // Same goes with booleans, the search bar can't render boolean values either.
                $value = $value ? 'y' : 'n';
            } elseif (is_string($value) && str_ends_with($this->column, 'id')) {
                $value = bin2hex($value);
            }

            yield $key => $value;
        }
    }
}
