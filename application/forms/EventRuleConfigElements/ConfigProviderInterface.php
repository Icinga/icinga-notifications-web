<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Schedule;

interface ConfigProviderInterface
{
    /**
     * Get a list of contacts to choose as part of a {@see EscalationRecipient}
     *
     * @return iterable<Contact> Properties {@see Contact::$id} and {@see Contact::$full_name} are required.
     */
    public function fetchContacts(): iterable;

    /**
     * Get a list of contact groups to choose as part of a {@see EscalationRecipient}
     *
     * @return iterable<Contactgroup> Properties {@see Contactgroup::$id} and {@see Contactgroup::$name} are required.
     */
    public function fetchContactGroups(): iterable;

    /**
     * Get a list of schedules to choose as part of a {@see EscalationRecipient}
     *
     * @return iterable<Schedule> Properties {@see Schedule::$id} and {@see Schedule::$name} are required.
     */
    public function fetchSchedules(): iterable;

    /**
     * Get a list of channels to choose as part of a {@see EscalationRecipient}
     *
     * @return iterable<Channel> Properties {@see Channel::$id} and {@see Channel::$name} are required.
     */
    public function fetchChannels(): iterable;
}
