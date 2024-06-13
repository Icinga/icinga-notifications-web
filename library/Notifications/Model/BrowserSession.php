<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;

/**
 * @property string $php_session_id
 * @property string $username
 * @property string $user_agent
 * @property DateTime $authenticated_at
 */
class BrowserSession extends Model
{
    public function getTableName(): string
    {
        return 'browser_session';
    }

    public function getKeyName(): array
    {
        return [
            'php_session_id',
            'username',
            'user_agent'
        ];
    }

    public function getColumns(): array
    {
        return [
            'php_session_id',
            'username',
            'user_agent',
            'authenticated_at'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'php_session_id'   => t('PHP\'s Session Identifier'),
            'username'         => t('Username'),
            'user_agent'       => t('User-Agent'),
            'authenticated_at' => t('Authenticated At')
        ];
    }

    public function getSearchColumns(): array
    {
        return [
            'php_session_id',
            'username',
            'user_agent'
        ];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['authenticated_at']));
    }
}
