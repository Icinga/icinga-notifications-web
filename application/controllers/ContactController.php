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
use Icinga\Module\Notifications\Data\NotificationConfigProvider;
use Icinga\Module\Notifications\Repository\ContactRepository;
use Icinga\Module\Notifications\Web\Form\ContactForm;
use Icinga\Repository\Repository;
use Icinga\Web\Notification;
use ipl\Html\Contract\Form;
use ipl\Sql\Connection;
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
        (new ContactForm(new NotificationConfigProvider()))
            ->on(Form::ON_REQUEST, function ($_, ContactForm $form) {
                $contact = (new ContactRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($contact === null) {
                    $this->httpNotFound($this->translate('Contact not found'));
                }

                $form->setContact($contact);

                $this->addTitleTab(sprintf($this->translate('Contact: %s'), $contact->full_name));

                $this->addContent($form);
            })
            ->on(Form::ON_SUBMIT, function (ContactForm $form) {
                $contact = $form->getContact();
                Database::get()->transaction(fn(Connection $db) => (new ContactRepository($db))->update($contact));
                Notification::success(sprintf(
                    $this->translate('Contact "%s" has successfully been saved'),
                    $contact->fullName
                ));

                $this->redirectNow('__CLOSE__');
            })->on(ContactForm::ON_REMOVE, function (ContactForm $form) {
                $contact = $form->getContact();
                Database::get()->transaction(fn(Connection $db) => (new ContactRepository($db))->delete($contact->id));
                Notification::success(sprintf(
                    $this->translate('Deleted contact "%s" successfully'),
                    $contact->fullName
                ));

                $this->redirectNow('__CLOSE__');
            })->on(Form::ON_SENT, function (ContactForm $form) {
                // TODO: I feel this should be part of CompatForm or CompatController (e.g. $this->sendForm())
                if (! $this->getResponse()->isRedirect()) {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })->handleRequest($this->getServerRequest());
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
