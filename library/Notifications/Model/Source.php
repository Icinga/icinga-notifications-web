<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Web\Widget\IcingaIcon;
use ipl\Web\Widget\Icon;

/**
 * @property int $id The primary key
 * @property string $type Type identifier
 * @property string $name The user-defined name
 * @property ?string $listener_password_hash
 * @property ?string $icinga2_base_url
 * @property ?string $icinga2_auth_user
 * @property ?string $icinga2_auth_pass
 * @property ?string $icinga2_ca_pem
 * @property ?string $icinga2_common_name
 * @property string $icinga2_insecure_tls
 * @property int $changed_at
 * @property bool $deleted
 */
class Source extends Model
{
    /** @var string The type name used by Icinga sources */
    public const ICINGA_TYPE_NAME = 'icinga2';

    public function getTableName()
    {
        return 'source';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'type',
            'name',
            'listener_password_hash',
            'icinga2_base_url',
            'icinga2_auth_user',
            'icinga2_auth_pass',
            'icinga2_ca_pem',
            'icinga2_common_name',
            'icinga2_insecure_tls',
            'changed_at',
            'deleted'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'type'          => t('Type'),
            'name'          => t('Name'),
            'changed_at'    => t('Changed At')
        ];
    }

    public function getSearchColumns()
    {
        return ['type'];
    }

    public function getDefaultSort()
    {
        return 'source.name';
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('object', Objects::class);
    }

    /**
     * Get the source icon
     *
     * @return Icon
     */
    public function getIcon(): Icon
    {
        switch ($this->type) {
            //TODO(sd): Add icons for other known sources
            case self::ICINGA_TYPE_NAME:
                $icon = new IcingaIcon('icinga');
                break;
            default:
                $icon = new Icon('share-nodes');
        }

        return $icon;
    }
}
