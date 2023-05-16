<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;

class Channel extends Model
{
    public function getTableName(): string
    {
        return 'channel';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'name',
            'type',
            'config'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'name'   => t('Name'),
            'type'   => t('Type'),
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }


    public function getDefaultSort()
    {
        return ['name'];
    }
}
