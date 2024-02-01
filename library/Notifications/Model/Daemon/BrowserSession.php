<?php

namespace Icinga\Module\Notifications\Model\Daemon;

use DateTime;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;

/**
 * @property string $php_session_id
 * @property string $username
 * @property string $browser_id
 * @property DateTime $authenticated_at
 */
class BrowserSession extends Model
{
    public function getTableName(): string
    {
        return 'browser_session';
    }

    /**
     * @return array<string>
     */
    public function getKeyName(): array
    {
        return [
            'php_session_id',
            'username',
            'browser_id'
        ];
    }

    public function getColumns(): array
    {
        return [
            'php_session_id',
            'username',
            'browser_id',
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
            'browser_id' => t('Browser Identifier'),
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
            'browser_id'
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
