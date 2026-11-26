<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\ProvidedHook\Director;

use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;
use Icinga\Module\Director\Hook\JobHook;
use Icinga\Module\Director\Objects\ImportSource;
use Icinga\Module\Director\Web\Form\DirectorObjectForm;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Form\Data\Contact as ContactData;
use Icinga\Module\Notifications\Form\Data\ContactGroup as ContactGroupData;
use Icinga\Module\Notifications\Repository\ContactGroupRepository;
use Icinga\Module\Notifications\Repository\ContactRepository;
use Icinga\Module\Notifications\Sync\ContactSyncPlan;
use Icinga\Module\Notifications\Sync\ContactSyncPlanner;
use ipl\Sql\Connection;
use ipl\Sql\Select;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Director job which synchronizes notification contacts and contact groups with the rows of an import source
 *
 * Any import source works, e.g. Director's LDAP import source to provision contacts from Active Directory.
 * See {@see ContactSyncPlanner} for the rules that are applied.
 */
class ContactSyncJob extends JobHook
{
    public function run()
    {
        $sourceName = (string) $this->getSetting('source');
        $source = ImportSource::loadByName($sourceName, $this->db());
        if ($this->getSetting('run_import') === 'y') {
            $source->runImport();
        }

        $addressColumns = static::parseAddressColumns((string) $this->getSetting('address_columns'));
        if (($emailColumn = $this->getOptionalSetting('email_column')) !== null) {
            $addressColumns = ['email' => $emailColumn] + $addressColumns;
        }

        $rows = [];
        foreach ($source->fetchLastRun(true)->fetchRows(null) as $row) {
            $rows[] = ContactSyncPlanner::extractRow(
                $row,
                (string) $this->getSetting('username_column'),
                $this->getOptionalSetting('full_name_column'),
                $addressColumns,
                $this->getOptionalSetting('groups_column')
            );
        }

        $db = Database::get();
        $db->transaction(function () use ($db, $sourceName, $rows, $addressColumns): void {
            $this->assertAddressTypesExist($db, array_keys($addressColumns));

            $channelTypes = [];
            $defaultChannelId = null;
            $defaultChannel = strtolower((string) $this->getSetting('default_channel'));
            $channels = (new Select())
                ->from('channel')
                ->columns(['id', 'external_uuid', 'type'])
                ->where(['deleted = ?' => 'n']);
            foreach ($db->select($channels) as $channel) {
                $channelTypes[(int) $channel->id] = $channel->type;
                if (static::uuidToString($channel->external_uuid) === $defaultChannel) {
                    $defaultChannelId = (int) $channel->id;
                }
            }

            if ($defaultChannelId === null) {
                throw new ConfigurationError('The default channel %s does not exist', $defaultChannel);
            }

            $planner = new ContactSyncPlanner(
                $sourceName,
                $defaultChannelId,
                $channelTypes,
                $this->getSetting('purge') === 'y'
            );

            $plan = $planner->plan($rows, $this->fetchContacts($db), $this->fetchGroups($db));
            foreach ($plan->warnings as $warning) {
                Logger::warning('Notifications contact sync (%s): %s', $sourceName, $warning);
            }

            $this->apply($db, $plan);

            Logger::info(
                'Notifications contact sync (%s): created %d groups, created %d, updated %d and deleted %d contacts',
                $sourceName,
                count($plan->createGroups),
                count($plan->createContacts),
                count($plan->updateContacts),
                count($plan->deleteContacts)
            );
        });
    }

    /**
     * Apply the given plan
     *
     * @param Connection $db
     * @param ContactSyncPlan $plan
     *
     * @return void
     */
    protected function apply(Connection $db, ContactSyncPlan $plan): void
    {
        $groupRepository = new ContactGroupRepository($db);
        $newGroupIds = [];
        foreach ($plan->createGroups as $key => $group) {
            $newGroupIds[$key] = $groupRepository->create(new ContactGroupData(
                id: null,
                name: $group['name'],
                externalUuid: $group['uuid']
            ));
        }

        $resolveGroups = function (array $refs) use ($newGroupIds): array {
            return array_map(fn(int|string $ref): int => is_int($ref) ? $ref : $newGroupIds[$ref], $refs);
        };

        $contactRepository = new ContactRepository($db);
        foreach ($plan->createContacts as $contact) {
            $contactRepository->create(new ContactData(
                id: null,
                fullName: $contact['fullName'],
                username: $contact['username'],
                channelId: $contact['channelId'],
                addresses: $contact['addresses'],
                groups: $resolveGroups($contact['groups']),
                externalUuid: $contact['uuid']
            ));
        }

        foreach ($plan->updateContacts as $contact) {
            $contactRepository->update(new ContactData(
                id: $contact['id'],
                fullName: $contact['fullName'],
                username: $contact['username'],
                channelId: $contact['channelId'],
                addresses: $contact['addresses'],
                groups: $resolveGroups($contact['groups'])
            ));
        }

        foreach (array_keys($plan->deleteContacts) as $id) {
            $contactRepository->delete($id);
        }
    }

    /**
     * Fetch all contacts in the format expected by {@see ContactSyncPlanner::plan()}
     *
     * @param Connection $db
     *
     * @return list<array{
     *     id: int,
     *     username: ?string,
     *     fullName: string,
     *     channelId: int,
     *     uuid: ?string,
     *     addresses: array<string, string>,
     *     groups: int[]
     * }>
     */
    protected function fetchContacts(Connection $db): array
    {
        $addresses = [];
        $addressQuery = (new Select())
            ->from('contact_address')
            ->columns(['contact_id', 'type', 'address'])
            ->where(['deleted = ?' => 'n']);
        foreach ($db->select($addressQuery) as $address) {
            $addresses[(int) $address->contact_id][$address->type] = $address->address;
        }

        $groups = [];
        $memberQuery = (new Select())
            ->from('contactgroup_member')
            ->columns(['contact_id', 'contactgroup_id'])
            ->where(['deleted = ?' => 'n']);
        foreach ($db->select($memberQuery) as $member) {
            $groups[(int) $member->contact_id][] = (int) $member->contactgroup_id;
        }

        $contacts = [];
        $contactQuery = (new Select())
            ->from('contact')
            ->columns(['id', 'username', 'full_name', 'default_channel_id', 'external_uuid'])
            ->where(['deleted = ?' => 'n']);
        foreach ($db->select($contactQuery) as $contact) {
            $id = (int) $contact->id;
            $contacts[] = [
                'id'        => $id,
                'username'  => $contact->username,
                'fullName'  => $contact->full_name,
                'channelId' => (int) $contact->default_channel_id,
                'uuid'      => static::uuidToString($contact->external_uuid),
                'addresses' => $addresses[$id] ?? [],
                'groups'    => $groups[$id] ?? []
            ];
        }

        return $contacts;
    }

    /**
     * Fetch all contact groups in the format expected by {@see ContactSyncPlanner::plan()}
     *
     * @param Connection $db
     *
     * @return list<array{id: int, name: string, uuid: ?string}>
     */
    protected function fetchGroups(Connection $db): array
    {
        $groups = [];
        $query = (new Select())
            ->from('contactgroup')
            ->columns(['id', 'name', 'external_uuid'])
            ->where(['deleted = ?' => 'n'])
            ->orderBy('id');
        foreach ($db->select($query) as $group) {
            $groups[] = [
                'id'   => (int) $group->id,
                'name' => $group->name,
                'uuid' => static::uuidToString($group->external_uuid)
            ];
        }

        return $groups;
    }

    /**
     * Assert that the given address types are known
     *
     * @param Connection $db
     * @param string[] $types
     *
     * @return void
     *
     * @throws ConfigurationError
     */
    protected function assertAddressTypesExist(Connection $db, array $types): void
    {
        if (empty($types)) {
            return;
        }

        $known = $db->fetchCol(
            (new Select())->from('available_channel_type')->columns('type')->where(['type IN (?)' => $types])
        );
        if (count($known) !== count($types)) {
            throw new ConfigurationError(
                'Unknown address type(s): %s',
                implode(', ', array_diff($types, $known))
            );
        }
    }

    /**
     * Parse address mappings of the form "type=column", separated by commas or newlines
     *
     * @param string $mappings
     *
     * @return array<string, string> Columns indexed by address type
     *
     * @throws ConfigurationError In case of an invalid mapping
     */
    public static function parseAddressColumns(string $mappings): array
    {
        $columns = [];
        foreach (preg_split('/[,\r\n]+/', $mappings, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mapping) {
            if (trim($mapping) === '') {
                continue;
            }

            $parts = array_map('trim', explode('=', $mapping, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new ConfigurationError('Invalid address mapping "%s", expected "type=column"', trim($mapping));
            }

            $columns[$parts[0]] = $parts[1];
        }

        return $columns;
    }

    /**
     * Get the given setting, NULL if it is not set or empty
     *
     * @param string $key
     *
     * @return ?string
     */
    protected function getOptionalSetting(string $key): ?string
    {
        $value = trim((string) $this->getSetting($key));

        return $value === '' ? null : $value;
    }

    /**
     * Get the string representation of the given UUID, which is binary on MySQL
     *
     * @param mixed $uuid
     *
     * @return ?string
     */
    protected static function uuidToString(mixed $uuid): ?string
    {
        if (is_resource($uuid)) {
            $uuid = stream_get_contents($uuid);
        }

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return strlen($uuid) === 16 ? Uuid::fromBytes($uuid)->toString() : strtolower($uuid);
    }

    public static function getDescription(QuickForm $form)
    {
        return $form->translate(
            'The "ContactSync" job keeps the contacts and contact groups of Icinga Notifications in sync'
            . ' with an import source, e.g. an LDAP import source. Contacts are matched by username. Contacts'
            . ' and groups created by this job are owned by it and identified by the name of the import source.'
        );
    }

    /**
     * @param QuickForm $form
     *
     * @return QuickForm
     *
     * @throws \Zend_Form_Exception
     */
    public static function addSettingsFormFields(QuickForm $form)
    {
        /** @var DirectorObjectForm $form */
        $db = $form->getDb()->getDbAdapter();
        $sources = $db->fetchCol($db->select()->from('import_source', 'source_name')->order('source_name'));

        $form->addElement('select', 'source', [
            'label'        => $form->translate('Import source'),
            'description'  => $form->translate(
                'The import source providing the contacts, e.g. an LDAP import source.'
                . ' Its name identifies the contacts and groups owned by this job, renaming'
                . ' the source makes the job lose track of them.'
            ),
            'required'     => true,
            'multiOptions' => ['' => $form->translate('- please choose -')] + array_combine($sources, $sources)
        ]);

        $form->addElement('select', 'run_import', [
            'label'        => $form->translate('Run import'),
            'description'  => $form->translate(
                'Whether to run the import before synchronizing, otherwise the last imported data is used'
            ),
            'value'        => 'n',
            'multiOptions' => [
                'y' => $form->translate('Yes'),
                'n' => $form->translate('No')
            ]
        ]);

        $form->addElement('text', 'username_column', [
            'label'       => $form->translate('Username column'),
            'description' => $form->translate(
                'The column holding the username, which identifies the contact and links it to the Icinga Web'
                . ' user with the same name, e.g. "sAMAccountName" or "uid"'
            ),
            'required'    => true,
            'value'       => 'sAMAccountName'
        ]);

        $form->addElement('text', 'full_name_column', [
            'label'       => $form->translate('Full name column'),
            'description' => $form->translate(
                'The column holding the full name of the contact, the username is used if empty'
            ),
            'value'       => 'displayName'
        ]);

        $form->addElement('text', 'email_column', [
            'label'       => $form->translate('Email column'),
            'description' => $form->translate('The column holding the email address of the contact'),
            'value'       => 'mail'
        ]);

        $form->addElement('text', 'address_columns', [
            'label'       => $form->translate('Other addresses'),
            'description' => $form->translate(
                'Further address mappings as comma separated "type=column" pairs, e.g. "rocketchat=uid".'
                . ' Only the mapped address types are managed, other addresses of a contact are preserved.'
            )
        ]);

        $form->addElement('text', 'groups_column', [
            'label'       => $form->translate('Groups column'),
            'description' => $form->translate(
                'The column holding the names of the contact groups the contact is a member of, e.g. "memberOf"'
                . ' combined with the "Extract from a Distinguished Name (DN)" property modifier. Missing groups'
                . ' are created. Memberships in groups which are neither listed here nor created by this job are'
                . ' preserved.'
            )
        ]);

        $channels = [];
        $channelError = null;
        try {
            $query = (new Select())
                ->from('channel')
                ->columns(['external_uuid', 'name'])
                ->where(['deleted = ?' => 'n'])
                ->orderBy('name');
            foreach (Database::get()->select($query) as $channel) {
                $channels[static::uuidToString($channel->external_uuid)] = $channel->name;
            }
        } catch (Throwable $e) {
            $channelError = $e->getMessage();
        }

        $form->addElement('select', 'default_channel', [
            'label'        => $form->translate('Default channel'),
            'description'  => $channelError === null
                ? $form->translate(
                    'The channel assigned to new contacts. They must have an address of its type.'
                    . ' Existing contacts keep their channel.'
                )
                : sprintf($form->translate('Failed to load the channels: %s'), $channelError),
            'required'     => true,
            'multiOptions' => ['' => $form->translate('- please choose -')] + $channels
        ]);

        $form->addElement('select', 'purge', [
            'label'        => $form->translate('Purge'),
            'description'  => $form->translate(
                'Whether to delete contacts created by this job once they vanish from the import source.'
                . ' Contacts not created by this job are never deleted.'
            ),
            'value'        => 'n',
            'multiOptions' => [
                'y' => $form->translate('Yes'),
                'n' => $form->translate('No')
            ]
        ]);

        return $form;
    }
}
