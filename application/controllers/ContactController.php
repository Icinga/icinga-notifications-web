<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Exception;
use Icinga\Application\Config;
use Icinga\Authentication\User\DomainAwareInterface;
use Icinga\Authentication\User\UserBackend;
use Icinga\Data\Selectable;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Web\Form\ContactForm;
use Icinga\Repository\Repository;
use Icinga\Web\Notification;
use ipl\Web\Compat\CompatController;
use ipl\Web\FormElement\SearchSuggestions;

class ContactController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('notifications/config/contacts');
    }

    public function indexAction(): void
    {
        $contactId = $this->params->getRequired('id');

        $form = (new ContactForm(Database::get()))
            ->loadContact($contactId)
            ->on(ContactForm::ON_SUCCESS, function (ContactForm $form) {
                $form->editContact();
                Notification::success(sprintf(
                    t('Contact "%s" has successfully been saved'),
                    $form->getContactName()
                ));

                $this->redirectNow('__CLOSE__');
            })->on(ContactForm::ON_REMOVE, function (ContactForm $form) {
                $form->removeContact();
                Notification::success(sprintf(
                    t('Deleted contact "%s" successfully'),
                    $form->getContactName()
                ));

                $this->redirectNow('__CLOSE__');
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf(t('Contact: %s'), $form->getContactName()));

        $this->addContent($form);
    }

    public function suggestIcingaWebUserAction(): void
    {
        $suggestions = new SearchSuggestions((function () use (&$suggestions) {
            $userBackends = [];
            foreach (Config::app('authentication') as $backendName => $backendConfig) {
                $candidate = UserBackend::create($backendName, $backendConfig);
                if ($candidate instanceof Selectable) {
                    $userBackends[] = $candidate;
                }
            }

            $limit = 10;
            while ($limit > 0 && ! empty($userBackends)) {
                /** @var Repository $backend */
                $backend = array_shift($userBackends);
                $query = $backend->select()
                    ->from('user', ['user_name'])
                    ->where('user_name', $suggestions->getSearchTerm())
                    ->limit($limit);

                try {
                    /** @var string[] $names */
                    $names = $query->fetchColumn();
                } catch (Exception) {
                    continue;
                }

                if (empty($names)) {
                    continue;
                }

                $domain = null;
                if ($backend instanceof DomainAwareInterface && $backend->getDomain()) {
                    $domain = '@' . $backend->getDomain();
                }

                foreach ($names as $name) {
                    yield [
                        'search' => $name . $domain,
                        'label'  => $name . $domain,
                        'backend' => $backend->getName(),
                    ];
                }

                $limit -= count($names);
            }
        })());

        $suggestions->setGroupingCallback(function (array $data) {
            return $data['backend'];
        });

        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->addHtml($suggestions);
    }
}
