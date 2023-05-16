<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class ContactAddress extends Model
{
    public function getTableName(): string
    {
        return 'contact_address';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'contact_id',
            'type',
            'address'
        ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('contact', Contact::class);
    }
}
