<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\ConfigurationTabs;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\View\EventRuleRenderer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\ItemList\ObjectList;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\TemplateString;
use ipl\Sql\Expression;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Layout\DetailedItemLayout;
use ipl\Web\Url;
use ipl\Web\Widget\ActionLink;
use ipl\Web\Widget\ButtonLink;

class EventRulesController extends CompatController
{
    use ConfigurationTabs;
    use SearchControls;

    public function init()
    {
        $this->assertPermission('notifications/config/event-rules');
    }

    public function indexAction(): void
    {
        $eventRules = Rule::on(Database::get());

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($eventRules);
        $sortControl = $this->createSortControl(
            $eventRules,
            [
                'name'          => $this->translate('Name'),
                'changed_at'    => $this->translate('Changed At')
            ]
        );

        $searchBar = $this->createSearchBar(
            $eventRules,
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

        $eventRules->filter($filter);

        $this->addControl($paginationControl);
        $this->addControl($sortControl);
        $this->addControl($limitControl);
        $this->addControl($searchBar);

        $addButton = new ButtonLink(
            $this->translate('Create Event Rule'),
            Url::fromPath('notifications/event-rules/add'),
            'plus',
            ['class' => 'add-new-component']
        );
        $this->addContent($addButton);

        $emptyStateMessage = null;
        if (Source::on(Database::get())->columns([new Expression('1')])->limit(1)->first() === null) {
            $addButton->disable($this->translate('A source is required to add an event rule'));

            $emptyStateMessage = TemplateString::create(
                $this->translate(
                    'No event rules found. To add a new event rule, please {{#link}}configure a Source{{/link}} first.'
                ),
                [
                    'link' => (new ActionLink(null, Links::sourceAdd()))->setBaseTarget('_next')
                ]
            );
        } else {
            $addButton->openInModal();
        }

        $this->addContent(
            (new ObjectList($eventRules, new EventRuleRenderer()))
                ->setItemLayoutClass(DetailedItemLayout::class)
                ->setEmptyStateMessage($emptyStateMessage)
        );

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setTitle($this->translate('Event Rules'));
        $this->getTabs()->activate('event-rules');
    }

    public function addAction(): void
    {
        $this->setTitle($this->translate('Create Event Rule'));

        $eventRuleForm = (new EventRuleForm())
            ->setIsNew()
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAvailableSources(
                Database::get()->fetchPairs(
                    Source::on(Database::get())->columns(['id', 'name'])->assembleSelect()
                )
            )
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function ($form) {
                $this->getResponse()->setHeader('X-Icinga-Container', 'col2');
                $this->redirectNow(Links::eventRule(-1)->addParams([
                    'name' => $form->getValue('name'),
                    'source' => $form->getValue('source')
                ]));
            })->handleRequest($this->getServerRequest());

        $this->addContent($eventRuleForm);
    }

    public function completeAction(): void
    {
        $suggestions = new ObjectSuggestions();
        $suggestions->setModel(Rule::class);
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction(): void
    {
        $editor = $this->createSearchEditor(
            Rule::on(Database::get()),
            [
                LimitControl::DEFAULT_LIMIT_PARAM,
                SortControl::DEFAULT_SORT_PARAM,
            ]
        );

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }
}
