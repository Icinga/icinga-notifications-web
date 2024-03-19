<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Model;

use Icinga\Module\Notifications\Model\Behavior\ObjectTags;
use ipl\Orm\Behaviors;
use ipl\Sql\Connection;

class ExtraTag extends ObjectExtraTag
{
    /**
     * @internal Don't use. This model acts only as relation target and is not supposed to be directly used as query
     *           target. Use {@see ObjectExtraTag} instead.
     */
    public static function on(Connection $_)
    {
        throw new \LogicException('Documentation says: DO NOT USE. Can\'t you read?');
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        parent::createBehaviors($behaviors);

        $behaviors->add(new ObjectTags());
    }
}
