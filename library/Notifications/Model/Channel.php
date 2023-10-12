<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Web\Widget\Icon;

class Channel extends Model
{
    public function getTableName(): string
    {
        return 'channel';
    }

    public function getKeyName(): string
    {
        return 'id';
    }

    public function getColumns(): array
    {
        return [
            'name',
            'type',
            'config'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'name'   => t('Name'),
            'type'   => t('Type'),
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }


    public function getDefaultSort()
    {
        return ['name'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('incident_history', IncidentHistory::class)->setJoinType('LEFT');
        $relations->hasMany('rule_escalation_recipient', RuleEscalationRecipient::class)->setJoinType('LEFT');
        $relations->hasMany('contact', Contact::class)
            ->setJoinType('LEFT')
            ->setForeignKey('default_channel_id');
        $relations->belongsTo('available_channel_type', AvailableChannelType::class)
            ->setCandidateKey('type');
    }

    /**
     * Get the channel icon
     *
     * @return Icon
     */
    public function getIcon(): Icon
    {
        switch ($this->type) {
            case 'rocketchat':
                $icon = new Icon('comment-dots');
                break;
            case 'email':
                $icon = new Icon('at');
                break;
            default:
                $icon = new Icon('envelope');
        }

        return $icon;
    }

    /**
     * Fetch and map all the configured channel types to a key => value array
     *
     * @param Connection $conn
     *
     * @return array<int, string> All the channel types mapped as id => type
     */
    public static function fetchChannelTypes(Connection $conn): array
    {
        $channels = [];
        foreach (Channel::on($conn) as $channel) {
            switch ($channel->type) {
                case 'rocketchat':
                    $name = 'Rocket.Chat';
                    break;
                case 'email':
                    $name = t('E-Mail');
                    break;
                default:
                    $name = $channel->type;
            }

            $channels[$channel->id] = $name;
        }

        return $channels;
    }
}
