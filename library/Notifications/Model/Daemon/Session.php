<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use DateTime;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;

/**
 * @property string $php_session_id
 * @property string $username
 * @property string $device_id
 * @property DateTime $authenticated_at
 */
class Session extends Model
{
    public function getTableName(): string
    {
        return 'session';
    }

    /**
     * @return array<string>
     */
    public function getKeyName(): array
    {
        return [
            'php_session_id',
            'username',
            'device_id'
        ];
    }

    public function getColumns(): array
    {
        return [
            'php_session_id',
            'username',
            'device_id',
            'authenticated_at'
        ];
    }

    /**
     * @return array<string>
     */
    public function getColumnDefinitions(): array
    {
        return [
            'php_session_id' => t('PHP\'s Session Identifier'),
            'username' => t('Username'),
            'device_id' => t('Device Identifier'),
            'authenticated_at' => t('Authenticated At')
        ];
    }

    /**
     * @return array<string>
     */
    public function getSearchColumns(): array
    {
        return [
            'php_session_id',
            'username',
            'device_id'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(
            new MillisecondTimestamp([
                'authenticated_at'
            ])
        );
    }
}
