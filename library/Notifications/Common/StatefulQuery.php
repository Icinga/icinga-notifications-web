<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Generator;
use ipl\Orm\Query;

/**
 * Mark models loaded from the db as not new, and can flag every result for deletion
 */
class StatefulQuery extends Query
{
    /** @var bool Whether all yielded models should be marked for deletion */
    protected bool $deleteAll = false;

    /**
     * Mark each yielded model as loaded, and for deletion when {@see self::deleteAll()} was set
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
                if ($this->deleteAll) {
                    $model->delete();
                }
            }

            yield $key => $model;
        }
    }

    /**
     * Mark each model as deleted when yielded by {@see self::yieldResults()}
     *
     * This only affects the root models themselves, not their eager-loaded relations
     *
     * @return $this
     */
    public function deleteAll(): static
    {
        $this->deleteAll = true;

        return $this;
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
