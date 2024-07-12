<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\ContactGroupForm;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ContactGroupList;
use Icinga\Module\Notifications\Widget\MemberSuggestions;
use Icinga\Web\Notification;
use ipl\Html\Form;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\Stdlib\Filter;
use ipl\Web\Common\BaseItemList;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\ButtonLink;
use ipl\Web\Widget\Tabs;

class ContactGroupsController extends CompatController
{
    use SearchControls;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function init(): void
    {
        $this->assertPermission('notifications/config/contact-groups');
    }

    public function indexAction(): void
    {
        $groups = Contactgroup::on(Database::get());

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($groups);
        $sortControl = $this->createSortControl(
            $groups,
            [
                'name'          => t('Group Name'),
                'changed_at'    => t('Changed At')
            ]
        );

        $searchBar = $this->createSearchBar(
            $groups,
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

        $groups->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);
        $this->addContent(
            (new ButtonLink(
                Text::create(t('Add Contact Group')),
                Links::contactGroupsAdd()->with(['showCompact' => true, '_disableLayout' => 1]),
                'plus',
                ['class' => 'add-new-component']
            ))->openInModal()
        );

        $this->addContent(new ContactGroupList($groups));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setTitle(t('Contact Groups'));
        $this->getTabs()->activate('contact-groups');
    }

    public function addAction(): void
    {
        $form = (new ContactGroupForm(Database::get()))
            ->setAction((string) Links::contactGroupsAdd()->with(['showCompact' => true, '_disableLayout' => 1]))
            ->on(Form::ON_SENT, function (ContactGroupForm $form) {
                if (! $form->hasBeenSubmitted()) {
                    foreach ($form->getPartUpdates() as $update) {
                        if (! is_array($update)) {
                            $update = [$update];
                        }

                        $this->addPart(...$update);
                    }
                }
            })
            ->on(Form::ON_SUCCESS, function (ContactGroupForm $form) {
                $groupIdentifier = $form->addGroup();

                Notification::success(t('New contact group has been successfully added'));
                $this->sendExtraUpdates(['#col1']);
                $this->getResponse()->setHeader('X-Icinga-Container', 'col2');
                $this->redirectNow(Links::contactGroup($groupIdentifier));
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
        $this->setTitle(t('Add Contact Group'));
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Contactgroup::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(
            Contactgroup::on(Database::get()),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    public function suggestMemberAction(): void
    {
        $members = new MemberSuggestions();
        $members->forRequest($this->getServerRequest());

        $this->getDocument()->addHtml($members);
    }

    public function getTabs(): Tabs
    {
        return parent::getTabs()
            ->add('schedules', [
                'label'      => t('Schedules'),
                'url'        => Links::schedules(),
                'baseTarget' => '_main'
            ])->add('event-rules', [
                'label'      => t('Event Rules'),
                'url'        => Links::eventRules(),
                'baseTarget' => '_main'
            ])->add('contacts', [
                'label'      => t('Contacts'),
                'url'        => Links::contacts(),
                'baseTarget' => '_main'
            ])->add('contact-groups', [
                'label'      => t('Contact Groups'),
                'url'        => Links::contactGroups(),
                'baseTarget' => '_main'
            ]);
    }

    protected function addContent(ValidHtml $content): self
    {
        if ($content instanceof BaseItemList) {
            $this->content->getAttributes()->add('class', 'full-width');
        }

        return parent::addContent($content);
    }

    /**
     * Get the filter created from query string parameters
     *
     * @return Filter\Rule
     */
    private function getFilter(): Filter\Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string) $this->params);
        }

        return $this->filter;
    }
}
