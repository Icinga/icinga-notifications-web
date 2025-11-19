<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model\Behavior;

use ipl\I18n\Translation;
use ipl\Orm\ColumnDefinition;
use ipl\Orm\Contract\RewriteColumnBehavior;
use ipl\Stdlib\Filter;

/** @internal Temporary implementation */
class IcingaCustomVars implements RewriteColumnBehavior
{
    use Translation;

    /** @var string */
    public const HOST_PREFIX = 'host.vars.';

    /** @var string */
    public const SERVICE_PREFIX = 'service.vars.';

    public function isSelectableColumn(string $name): bool
    {
        return str_starts_with($name, self::HOST_PREFIX)
            || str_starts_with($name, self::SERVICE_PREFIX);
    }

    public function rewriteColumn($column, ?string $relation = null): null
    {
        return null;
    }

    public function rewriteColumnDefinition(ColumnDefinition $def, string $relation): void
    {
        if (str_starts_with($def->getName(), self::HOST_PREFIX)) {
            $varName = substr($def->getName(), strlen(self::HOST_PREFIX));
        } elseif (str_starts_with($def->getName(), self::SERVICE_PREFIX)) {
            $varName = substr($def->getName(), strlen(self::SERVICE_PREFIX));
        } else {
            return;
        }

        if (str_ends_with($varName, '[*]')) {
            $varName = substr($varName, 0, -3);
        }

        $def->setLabel(sprintf(
            $this->translate(
                ucfirst(substr($def->getName(), 0, strpos($def->getName(), '.'))) . ' %s',
            ),
            $varName
        ));
    }

    public function rewriteCondition(Filter\Condition $condition, $relation = null): Filter\Condition|Filter\Rule|null
    {
        if (! $this->isSelectableColumn($condition->metaData()->get('columnName', ''))) {
            return null;
        }

        $class = get_class($condition);

        return new $class(
            $relation . 'object.extra_tag.' . substr($condition->getColumn(), strlen($relation)),
            $condition->getValue()
        );
    }
}
