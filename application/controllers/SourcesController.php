<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\SourceList;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tabs;
use ipl\Html\ValidHtml;
use ipl\Stdlib\Filter;
use ipl\Web\Common\BaseItemList;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;

class SourcesController extends CompatController
{
    use SearchControls;

    public function init()
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        $sources = Source::on(Database::get())
            ->columns(['id', 'type',  'name'])
            ->filter(Filter::equal('deleted', 'n'));

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

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);
        $this->addContent(
            (new ButtonLink(
                t('Add Source'),
                Url::fromPath('notifications/sources/add'),
                'plus'
            ))->setBaseTarget('_next')
                ->addAttributes(['class' => 'add-new-component'])
        );

        $this->mergeTabs($this->Module()->getConfigTabs());
        $this->getTabs()->activate('sources');
        $this->addContent(new SourceList($sources->execute()));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction(): void
    {
        $form = (new SourceForm(Database::get()))
            ->on(SourceForm::ON_SUCCESS, function (SourceForm $form) {
                $form->addSource();
                Notification::success(sprintf(t('Added new source %s has successfully'), $form->getSourceName()));
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
     * Add attribute 'class' => 'full-width' if the content is an instance of BaseItemList
     *
     * @param ValidHtml $content
     *
     * @return $this
     */
    protected function addContent(ValidHtml $content)
    {
        if ($content instanceof BaseItemList) {
            $this->content->getAttributes()->add('class', 'full-width');
        }

        return parent::addContent($content);
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
