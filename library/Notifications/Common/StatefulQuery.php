<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Generator;
use ipl\Orm\Query;

/**
 * Ensures models loaded from the db are not marked as new
 */
class StatefulQuery extends Query
{
    /**
     * Mark yielded models as loaded so subsequent changes are tracked as updates
     *
     * @inheritDoc
     *
     * @return Generator
     */
    public function yieldResults(): Generator
    {
        foreach (parent::yieldResults() as $key => $model) {
            $model->setNew(false);

            yield $key => $model;
        }
    }
}
