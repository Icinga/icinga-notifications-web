<?php

namespace Icinga\Module\Noma\Model;

use ipl\Orm\Model;

class Contactgroup extends Model
{
    public function getTableName()
    {
        return 'contactgroup';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'name',
            'color'
        ];
    }
}
