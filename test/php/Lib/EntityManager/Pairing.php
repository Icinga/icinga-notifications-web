<?php

namespace Tests\Icinga\Module\Notifications\Lib\EntityManager;

use Icinga\Module\Notifications\Common\Model;

class Pairing extends Model
{
    public function getTableName()
    {
        return 'pairing';
    }

    public function getKeyName(): array
    {
        return ['left_id', 'right_id'];
    }

    public function getColumns()
    {
        return ['left_id', 'right_id', 'label'];
    }
}