<?php

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;

class Sticker extends Model
{
    public function getTableName()
    {
        return 'sticker';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return ['label'];
    }
}
