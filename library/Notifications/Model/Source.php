<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Model;

use DateTime;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Hook\V1\SourceHook;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Web\Widget\Icon;
use Throwable;

/**
 * @property int $id The primary key
 * @property string $type Type identifier
 * @property string $name The user-defined name
 * @property ?string $listener_username The username for HTTP authentication
 * @property ?string $listener_password_hash
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|Objects $object
 * @property Query|Rule $rule
 */
class Source extends Model
{
    /** @var array<string, Icon> */
    private static array $icons = [];

    public function getTableName(): string
    {
        return 'source';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'type',
            'name',
            'listener_username',
            'listener_password_hash',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'type'              => t('Type'),
            'name'              => t('Name'),
            'listener_username' => t('Username'),
            'changed_at'        => t('Changed At')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['type'];
    }

    public function getDefaultSort(): string
    {
        return 'source.name';
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
    {
        $relations->hasMany('object', Objects::class);
        $relations->hasMany('rule', Rule::class);
    }

    /**
     * Get the source icon
     *
     * @return Icon
     */
    public function getIcon(): Icon
    {
        if (array_key_exists($this->type, self::$icons)) {
            return self::$icons[$this->type];
        }

        // Fallback, in case an integration is inactive or missing
        $icon = new Icon('share-nodes');

        foreach (Hook::all('Notifications/v1/Source') as $hook) {
            /** @var SourceHook $hook */
            try {
                if ($hook->getSourceType() === $this->type) {
                    $icon = $hook->getSourceIcon();

                    break;
                }
            } catch (Throwable $e) {
                Logger::error(
                    'Failed to retrieve source icon for source type "%s": %s',
                    $this->type,
                    $e
                );

                break;
            }
        }

        // Cache icons, they don't change during a session but are required multiple times
        self::$icons[$this->type] = $icon;

        return $icon;
    }
}
