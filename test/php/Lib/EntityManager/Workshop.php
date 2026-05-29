<?php

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;
use ipl\Orm\Relations;

class Workshop extends Model
{
    public function getTableName()
    {
        return 'workshop';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['name'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('gadgets', Gadget::class);
    }
}
