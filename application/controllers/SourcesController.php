<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Hook;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\View\SourceRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use Icinga\Web\Widget\Tabs;
use ipl\Html\Contract\Form;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\MinimalItemLayout;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;

class SourcesController extends CompatController
{
    use SearchControls;

    public function init(): void
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        $this->mergeTabs($this->Module()->getConfigTabs());
        $this->getTabs()->activate('sources');

        $sources = Source::on(Database::get())
            ->columns(['id', 'type', 'name']);

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($sources);
        $sortControl = $this->createSortControl(
            $sources,
            [
                'name'          => t('Name'),
                'type'          => t('Type'),
                'changed_at'    => t('Changed At')
            ]
        );

        $searchBar = $this->createSearchBar(
            $sources,
            [
                $limitControl->getLimitParam(),
                $sortControl->getSortParam()
            ]
        );

        if ($searchBar->hasBeenSent() && ! $searchBar->isValid()) {
            if ($searchBar->hasBeenSubmitted()) {
                $filter = QueryString::parse((string) $this->params);
            } else {
                $this->addControl($searchBar);
                $this->sendMultipartUpdate();
                return;
            }
        } else {
            $filter = $searchBar->getFilter();
        }

        $sources->filter($filter);

        $addButton = new ButtonLink(
            t('Add Source'),
            Url::fromPath('notifications/sources/add'),
            'plus',
            ['class' => 'add-new-component']
        );
        $emptyStateMessage = null;
        if (! Hook::has('Notifications/v1/Source')) {
            $addButton->disable($this->translate(
                'You have to install a module that serves as an integration for a source first.'
            ));
            $emptyStateMessage = $this->translate(
                'No sources found. To add a new source, please install a module that serves as an integration'
                . ' for a source first. Notable examples are Icinga DB Web and Icinga for Kubernetes Web.'
            );
        } else {
            $addButton->setBaseTarget('_next');
        }

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);
        $this->addContent($addButton);

        $this->addContent(
            (new ObjectList($sources, new SourceRenderer()))
                ->setItemLayoutClass(MinimalItemLayout::class)
                ->setEmptyStateMessage($emptyStateMessage)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction(): void
    {
        $form = (new SourceForm(Database::get()))
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->on(Form::ON_SUBMIT, function (SourceForm $form) {
                $form->addSource();
                Notification::success(sprintf(t('Added new source %s successfully'), $form->getSourceName()));
                $this->switchToSingleColumnLayout();
            })
            ->handleRequest($this->getServerRequest());

        $this->addTitleTab($this->translate('Add Source'));
        $this->addContent($form);
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Source::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(
            Source::on(Database::get()),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->setTitle($this->translate('Adjust Filter'));
        $this->getDocument()->add($editor);
    }

    /**
     * Merge tabs with other tabs contained in this tab panel
     *
     * @param Tabs $tabs
     *
     * @return void
     */
    protected function mergeTabs(Tabs $tabs): void
    {
        foreach ($tabs->getTabs() as $tab) {
            $name = $tab->getName();
            if ($name) {
                $this->tabs->add($name, $tab);
            }
        }
    }
}
