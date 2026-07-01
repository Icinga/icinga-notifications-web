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
            if ($model instanceof Model) {
                $this->markLoaded($model);
            }

            yield $key => $model;
        }
    }

    /**
     * Recursively mark the given model and its eagerly-loaded related models as loaded
     *
     * @param Model $model
     *
     * @return void
     */
    private function markLoaded(Model $model): void
    {
        if ($model->isNew() !== null) {
            return;
        }

        $model->setNew(false);

        foreach ($model as $value) {
            if ($value instanceof Model) {
                $this->markLoaded($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Model) {
                        $this->markLoaded($item);
                    }
                }
            }
        }
    }
}
