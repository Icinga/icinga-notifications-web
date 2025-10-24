<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\ConfigurationTabs;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\View\ContactRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Web\Form\ContactForm;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Notification;
use ipl\Html\Contract\Form;
use ipl\Html\TemplateString;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Widget\ActionLink;
use ipl\Web\Widget\ButtonLink;

class ContactsController extends CompatController
{
    use ConfigurationTabs;
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

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($contacts);
        $sortControl = $this->createSortControl(
            $contacts,
            [
                'full_name'     => $this->translate('Full Name'),
                'changed_at'    => $this->translate('Changed At')
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

        $addButton = (new ButtonLink(
            $this->translate('Add Contact'),
            Links::contactAdd(),
            'plus',
            ['class' => 'add-new-component']
        ))->setBaseTarget('_next');

        $emptyStateMessage = null;
        if (Channel::on($this->db)->columns([new Expression('1')])->limit(1)->first() === null) {
            $addButton->disable($this->translate('A channel is required to add a contact'));

            $emptyStateMessage = TemplateString::create(
                $this->translate(
                    'No contacts found. To add a new contact, please {{#link}}configure a Channel{{/link}} first.'
                ),
                ['link' => (new ActionLink(null, Links::channelAdd()))->setBaseTarget('_next')]
            );
        }

        $this->addContent($addButton);

        $this->addContent(
            (new ObjectList($contacts, new ContactRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setEmptyStateMessage($emptyStateMessage)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setAutorefreshInterval(30);

        $this->setTitle($this->translate('Contacts'));
        $this->getTabs()->activate('contacts');
    }

    public function addAction(): void
    {
        $this->addTitleTab($this->translate('Add Contact'));

        $form = (new ContactForm($this->db))
            ->on(Form::ON_SUBMIT, function (ContactForm $form) {
                $form->addContact();
                Notification::success($this->translate('New contact has successfully been added'));
                $this->switchToSingleColumnLayout();
            })->handleRequest($this->getServerRequest());

        $this->addContent($form);
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
}
