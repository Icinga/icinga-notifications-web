<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class AvailableChannelType extends Model
{
    public function getTableName(): string
    {
        return 'available_channel_type';
    }

    public function getKeyName()
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
