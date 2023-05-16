<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Common\BaseItemList;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Web\Form\ContactForm;
use Icinga\Module\Notifications\Widget\ItemList\ContactList;
use Icinga\Web\Notification;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;
use ipl\Html\ValidHtml;
use ipl\Web\Widget\Tabs;

class ContactsController extends CompatController
{
    use SearchControls;

    /** @var Connection */
    private $db;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function init()
    {
        $this->assertPermission('notifications/config/contacts');

        $this->db = Database::get();
    }

    public function indexAction()
    {
        $contacts = Contact::on($this->db);

        $contacts->withColumns(
            [
                'has_email',
                'has_rc'
            ]
        );

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($contacts);
        $sortControl = $this->createSortControl(
            $contacts,
            [
                'full_name' => t('Full Name'),
            ]
        );

        $searchBar = $this->createSearchBar(
            $contacts,
            [
                $limitControl->getLimitParam(),
                $sortControl->getSortParam()
            ]
        );

        if ($searchBar->hasBeenSent() && ! $searchBar->isValid()) {
            if ($searchBar->hasBeenSubmitted()) {
                $filter = $this->getFilter();
            } else {
                $this->addControl($searchBar);
                $this->sendMultipartUpdate();
                return;
            }
        } else {
            $filter = $searchBar->getFilter();
        }

        $contacts->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);
        $this->addContent(
            (new ButtonLink(
                t('Add Contact'),
                'notifications/contacts/add',
                'plus'
            ))->setBaseTarget('_next')
            ->addAttributes(['class' => 'add-new-component'])
        );

        $this->addContent(new ContactList($contacts));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setAutorefreshInterval(30);

        $this->setTitle($this->translate('Contacts'));
        $this->getTabs()->activate('contacts');
    }

    public function addAction()
    {
        $this->addTitleTab(t('Add Contact'));

        $form = (new ContactForm($this->db))
            ->on(ContactForm::ON_SUCCESS, function (ContactForm $form) {
                $form->addOrUpdateContact();
                Notification::success(t('New contact has successfully been added'));
                $this->redirectNow(Url::fromPath('notifications/contacts'));
            })->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }

    /**
     * Add attribute 'class' => 'full-width' if the content is an instance of BaseItemList
     *
     * @param ValidHtml $content
     *
     * @return ContactsController
     */
    protected function addContent(ValidHtml $content)
    {
        if ($content instanceof BaseItemList) {
            $this->content->getAttributes()->add('class', 'full-width');
        }

        return parent::addContent($content);
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Contact::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(
            Contact::on($this->db),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    /**
     * Get the filter created from query string parameters
     *
     * @return Filter\Rule
     */
    protected function getFilter(): Filter\Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string) $this->params);
        }

        return $this->filter;
    }

    public function getTabs()
    {
        if ($this->getRequest()->getActionName() === 'index') {
            return parent::getTabs()
                ->add('contacts', [
                    'label' => $this->translate('Contacts'),
                    'url'   => Url::fromRequest()
                ])
                ->add('event-rules', [
                    'label' => $this->translate('Event Rules'),
                    'url'   => Url::fromPath('notifications/event-rules')
                ])
                ->add('schedules', [
                    'label'         => $this->translate('Schedules'),
                    'url'           => Url::fromPath('notifications/schedules'),
                    'baseTarget'    => '_main'
                ]);
        }

        return parent::getTabs();
    }
}
