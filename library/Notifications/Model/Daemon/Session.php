<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;

class Session extends Model {
    public function getTableName(): string {
        return 'session';
    }

    public function getKeyName(): array {
        return [
            'id',
            'username',
            'device_id'
        ];
    }

    public function getColumns(): array {
        return [
            'id',
            'username',
            'device_id',
            'authenticated_at'
        ];
    }

    public function getColumnDefinitions(): array {
        return [
            'id' => t('Session Identifier'),
            'username' => t('Username'),
            'device_id' => t('Device Identifier'),
            'authenticated_at' => t('Authenticated At')
        ];
    }

    public function getSearchColumns(): array {
        return [
            'id',
            'username',
            'device_id'
        ];
    }

    public function createBehaviors(Behaviors $behaviors) {
        $behaviors->add(new MillisecondTimestamp([
            'authenticated_at'
        ]));
    }


}
