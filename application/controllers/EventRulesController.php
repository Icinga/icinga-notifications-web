<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Exception\ProgrammingError;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Forms\SaveEventRuleForm;
use Icinga\Module\Notifications\Model\ObjectExtraTag;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Notifications\Widget\EventRuleConfig;
use Icinga\Module\Notifications\Widget\ItemList\EventRuleList;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRulesController extends CompatController
{
    use SearchControls;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    /** @var Session\SessionNamespace */
    private $sessionNamespace;

    public function init()
    {
        $this->assertPermission('notifications/config/event-rules');
        $this->sessionNamespace = Session::getSession()->getNamespace('notifications');
    }

    public function indexAction(): void
    {
        $eventRules = Rule::on(Database::get());

        $limitControl = $this->createLimitControl();
        $paginationControl = $this->createPaginationControl($eventRules);
        $sortControl = $this->createSortControl(
            $eventRules,
            [
                'name' => t('Name'),
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
                $filter = $this->getFilter();
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
        $this->addContent(
            (new ButtonLink(
                t('New Event Rule'),
                Url::fromPath('notifications/event-rule/edit', ['id' => -1, 'clearCache' => true]),
                'plus'
            ))->openInModal()
            ->addAttributes(['class' => 'new-event-rule'])
        );

        $this->addContent(new EventRuleList($eventRules));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }

        $this->setTitle($this->translate('Event Rules'));
        $this->getTabs()->activate('event-rules');
    }

    public function addAction(): void
    {
        $this->addTitleTab(t('Add Event Rule'));
        $this->getTabs()->setRefreshUrl(Url::fromPath('notifications/event-rules/add'));

        $this->controls->addAttributes(['class' => 'event-rule-detail']);

        if ($this->params->has('use_cache') || $this->getServerRequest()->getMethod() !== 'GET') {
            $cache = $this->sessionNamespace->get(-1, []);
        } else {
            $this->sessionNamespace->delete(-1);

            $cache = [];
        }

        $eventRuleConfig = new EventRuleConfig(Url::fromPath('notifications/event-rules/add-search-editor'), $cache);

        $eventRuleForm = Html::tag('div', ['class' => 'event-rule-form'], [
            Html::tag('h2', $eventRuleConfig->getConfig()['name'] ?? ''),
            (new Link(
                new Icon('edit'),
                Url::fromPath('notifications/event-rule/edit', [
                    'id' => -1
                ]),
                ['class' => 'control-button']
            ))->openInModal()
        ]);

        $saveForm = (new SaveEventRuleForm())
            ->on(SaveEventRuleForm::ON_SUCCESS, function ($saveForm) use ($eventRuleConfig) {
                if (! $eventRuleConfig->isValid()) {
                    $eventRuleConfig->addAttributes(['class' => 'invalid']);
                    return;
                }

                $id = $saveForm->addRule($this->sessionNamespace->get(-1));

                Notification::success($this->translate('Successfully added rule.'));
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow(Links::eventRule($id));
            })->handleRequest($this->getServerRequest());

        $eventRuleConfig->on(EventRuleConfig::ON_CHANGE, function ($eventRuleConfig) {
            $this->sessionNamespace->set(-1, $eventRuleConfig->getConfig());

            $this->redirectNow(Url::fromPath('notifications/event-rules/add', ['use_cache' => true]));
        });

        foreach ($eventRuleConfig->getForms() as $f) {
            $f->handleRequest($this->getServerRequest());

            if (! $f->hasBeenSent()) {
                // Force validation of populated values in case we display an unsaved rule
                $f->validatePartial();
            }
        }

        $eventRuleFormAndSave = Html::tag('div', ['class' => 'event-rule-and-save-forms']);
        $eventRuleFormAndSave->add([
            $eventRuleForm,
            $saveForm
        ]);

        $this->addControl($eventRuleFormAndSave);
        $this->addContent($eventRuleConfig);
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

    public function addSearchEditorAction(): void
    {
        $cache = $this->sessionNamespace->get(-1);

        $editor = EventRuleConfig::createSearchEditor()
            ->setQueryString($cache['object_filter'] ?? '');

        $editor->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) {
            $cache = $this->sessionNamespace->get(-1);
            $cache['object_filter'] = EventRuleConfig::createFilterString($form->getFilter());

            $this->sessionNamespace->set(-1, $cache);

            $this->getResponse()
                ->setHeader('X-Icinga-Container', '_self')
                ->redirectAndExit(
                    Url::fromPath(
                        'notifications/event-rules/add',
                        ['use_cache' => true]
                    )
                );
        });

        $editor->handleRequest($this->getServerRequest());

        $this->getDocument()->addHtml($editor);
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
                ->add('schedules', [
                    'label'         => $this->translate('Schedules'),
                    'url'           => Url::fromPath('notifications/schedules'),
                    'baseTarget'    => '_main'
                ])->add('event-rules', [
                    'label'      => $this->translate('Event Rules'),
                    'url'        => Url::fromPath('notifications/event-rules'),
                    'baseTarget' => '_main'
                ])->add('contacts', [
                    'label'      => $this->translate('Contacts'),
                    'url'        => Url::fromPath('notifications/contacts'),
                    'baseTarget' => '_main'
                ]);
        }

        return parent::getTabs();
    }
}
