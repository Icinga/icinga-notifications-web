<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Controllers;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Common\Links;
use Icinga\Module\Noma\Forms\EventRuleForm;
use Icinga\Module\Noma\Forms\SaveEventRuleForm;
use Icinga\Module\Noma\Model\ObjectExtraTag;
use Icinga\Module\Noma\Model\Rule;
use Icinga\Module\Noma\Web\Control\SearchBar\ObjectSuggestions;
use Icinga\Module\Noma\Widget\EventRuleConfig;
use Icinga\Module\Noma\Widget\ItemList\EventRuleList;
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

class EventRulesController extends CompatController
{
    use SearchControls;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    /** @var Session\SessionNamespace */
    private $sessionNamespace;

    public function init()
    {
        $this->assertPermission('noma/config/event-rules');
        $this->sessionNamespace = Session::getSession()->getNamespace('noma');
    }

    public function indexAction(): void
    {
        $this->addTitleTab(t('Event Rules'));

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
                'noma/event-rules/add',
                'plus'
            ))->setBaseTarget('_next')
            ->addAttributes(['class' => 'new-event-rule'])
        );

        $this->addContent(new EventRuleList($eventRules));

        if (! $searchBar->hasBeenSubmitted() && $searchBar->hasBeenSent()) {
            $this->sendMultipartUpdate();
        }
    }

    public function addAction(): void
    {
        $this->addTitleTab(t('Add Event Rule'));
        $this->getTabs()->setRefreshUrl(Url::fromPath('noma/event-rules/add'));

        if ($this->params->has('use_cache') || $this->getServerRequest()->getMethod() !== 'GET') {
            $cache = $this->sessionNamespace->get(-1, []);
        } else {
            $this->sessionNamespace->delete(-1);

            $cache = [];
        }

        $eventRuleConfig = new EventRuleConfig(Url::fromPath('noma/event-rules/add-search-editor'), $cache);

        $eventRuleForm = (new EventRuleForm())
            ->populate($cache)
            ->on(Form::ON_SENT, function ($form) use ($eventRuleConfig) {
                $config = $eventRuleConfig->getConfig();
                $config['name'] = $form->getValue('name');
                $config['is_active'] = $form->getValue('is_active');

                $eventRuleConfig->setConfig($config);
                $this->sessionNamespace->set(-1, $eventRuleConfig->getConfig());
            })->handleRequest($this->getServerRequest());

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

            $this->redirectNow(Url::fromPath('noma/event-rules/add', ['use_cache' => true]));
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
                        'noma/event-rules/add',
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
}
