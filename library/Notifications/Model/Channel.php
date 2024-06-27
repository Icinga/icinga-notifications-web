<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use DateTime;
use ipl\Orm\Behavior\BoolCast;
use ipl\Orm\Behavior\MillisecondTimestamp;
use ipl\Orm\Behaviors;
use ipl\Orm\Model;
use ipl\Orm\Query;
use ipl\Orm\Relations;
use ipl\Sql\Connection;
use ipl\Web\Widget\Icon;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property ?string $config
 * @property DateTime $changed_at
 * @property bool $deleted
 *
 * @property Query|IncidentHistory $incident_history
 * @property Query|RuleEscalationRecipient $rule_escalation_recipient
 * @property Query|Contact $contact
 * @property Query|AvailableChannelType $available_channel_type
 */
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
            'config',
            'changed_at',
            'deleted',
            'external_uuid'
        ];
    }

    public function getColumnDefinitions(): array
    {
        return [
            'name'          => t('Name'),
            'type'          => t('Type'),
            'external_uuid' => t('UUID')
        ];
    }

    public function getSearchColumns(): array
    {
        return ['name'];
    }


    public function getDefaultSort(): array
    {
        return ['name'];
    }

    public function createBehaviors(Behaviors $behaviors): void
    {
        $behaviors->add(new MillisecondTimestamp(['changed_at']));
        $behaviors->add(new BoolCast(['deleted']));
    }

    public function createRelations(Relations $relations): void
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
        $query = Channel::on($conn);
        /** @var Channel $channel */
        foreach ($query as $channel) {
            $name = $channel->name;
            $channels[$channel->id] = $name;
        }

        return $channels;
    }
}
