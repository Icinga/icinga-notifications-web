<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Web\Widget\Icon;

/**
 * @property int $id The primary key
 * @property string $type Type identifier
 * @property string $name The user-defined name
 * @property ?string $listener_password_hash
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Objects $object
 * @property Query|Rule $rule
 */
class Source extends Model
{
    public function getTableName(): string
    {
        return 'source';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'type',
            'name',
            'listener_password_hash',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'type'          => t('Type'),
            'name'          => t('Name'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['type'];
    }

    public function getDefaultSort(): string
    {
        return 'source.name';
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('object', Objects::class);
        $relations->hasMany('rule', Rule::class);
    }

    /**
     * Get the source icon
     *
     * @return Icon
     */
    public function getIcon(): Icon
    {
        // TODO: Let the hook deliver the icon
        return new Icon('share-nodes');
    }
}
