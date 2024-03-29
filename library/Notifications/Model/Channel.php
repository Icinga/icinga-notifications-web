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
     * Fetch and map all the configured channel names to a key => value array
     *
     * @param Connection $conn
     *
     * @return string[] All the channel names mapped as id => name
     */
    public static function fetchChannelNames(Connection $conn): array
    {
        $channels = [];
        /** @var Channel $channel */
        foreach (Channel::on($conn) as $channel) {
            /** @var string $name */
            $name = $channel->name;
            $channels[$channel->id] = $name;
        }

        return $channels;
    }
}
