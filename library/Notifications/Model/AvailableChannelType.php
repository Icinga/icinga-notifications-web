<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;

/**
 * @property string $type
 * @property string $name
 * @property string $version
 * @property string $author_name
 * @property string $config_attrs
 *
 * @property Query|Channel $channel
 */
class AvailableChannelType extends Model
{
    public function getTableName(): string
    {
        return 'available_channel_type';
    }

    public function getKeyName(): string
    {
        return 'type';
    }

    public function getColumns(): array
    {
        return [
            'name',
            'version',
            'author_name',
            'config_attrs',
        ];
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('channel', Channel::class)
            ->setForeignKey('type');
    }
}
