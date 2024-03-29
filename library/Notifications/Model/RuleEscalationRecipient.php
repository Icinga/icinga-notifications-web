<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class RuleEscalationRecipient extends Model
{
    public function getTableName()
    {
        return 'rule_escalation_recipient';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'rule_escalation_id',
            'contact_id',
            'contactgroup_id',
            'schedule_id',
            'channel_id'
        ];
    }

    public function getColumnDefinitions()
    {
        return [
            'rule_escalation_id' => t('Rule Escalation ID'),
            'contact_id'         => t('Contact ID'),
            'contactgroup_id'    => t('Contactgroup ID'),
            'schedule_id'        => t('Schedule ID'),
            'channel_id'         => t('Channel ID')
        ];
    }

    public function getDefaultSort()
    {
        return ['rule_escalation_id'];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('rule_escalation', RuleEscalation::class);
        $relations->belongsTo('contact', Contact::class);
        $relations->belongsTo('schedule', Schedule::class);
        $relations->belongsTo('contactgroup', Contactgroup::class);
        $relations->belongsTo('channel', Channel::class);
    }

    /**
     * Get the recipient model
     *
     * @return ?Model
     */
    public function getRecipient()
    {
        $recipientModel = null;
        if ($this->contact_id) {
            $recipientModel = $this->contact->first();
        }

        if ($this->contactgroup_id) {
            $recipientModel = $this->contactgroup->first();
        }

        if ($this->schedule_id) {
            $recipientModel = $this->schedule->first();
        }

        return $recipientModel;
    }
}
