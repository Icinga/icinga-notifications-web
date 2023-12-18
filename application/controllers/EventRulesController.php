<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Widget\ItemList\EventRuleList;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Compat\SearchControls;
use ipl\Web\Control\SearchEditor;
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
        $this->sessionNamespace->delete('-1');

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
                Url::fromPath('notifications/event-rule/edit', ['id' => -1]),
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
        $this->getTabs()->setRefreshUrl(Url::fromPath('notifications/event-rules/add', ['id' => '-1']));

        $this->controls->addAttributes(['class' => 'event-rule-detail']);
        $ruleId = $this->params->get('id');
        $config = $this->sessionNamespace->get($ruleId);
        $config['object_filter'] = $config['object_filter'] ?? null;

        $eventRuleConfigSubmitButton = (new SubmitButtonElement(
            'save',
            [
                'label'          => t('Add Event Rule'),
                'form'           => 'event-rule-config-form'
            ]
        ))->setWrapper(new HtmlElement('div', Attributes::create(['class' => ['icinga-controls', 'save-config']])));

        $eventRuleConfig = new EventRuleConfigForm(
            $config,
            Url::fromPath(
                'notifications/event-rules/search-editor',
                ['id' => $ruleId]
            )
        );

        $eventRuleConfig
            ->populate($config)
            ->on(Form::ON_SUCCESS, function (EventRuleConfigForm $form) use ($config) {
                $ruleId = (int) $config['id'];
                $ruleName = $config['name'];
                $insertId = $form->addOrUpdateRule($ruleId, $config);
                $this->sessionNamespace->delete($ruleId);
                Notification::success(sprintf(t('Successfully add event rule %s'), $ruleName));
                $this->redirectNow(Links::eventRule($insertId));
            })
            ->on(EventRuleConfigForm::ON_CHANGE, function (EventRuleConfigForm $form) use ($config) {
                $formValues = $form->getValues();
                $config = array_merge($config, $formValues);
                $config['rule_escalation'] = $formValues['rule_escalation'];
                $this->sessionNamespace->set('-1', $config);
            })
            ->handleRequest($this->getServerRequest());

        $eventRuleForm = Html::tag('div', ['class' => 'event-rule-form'], [
            Html::tag('h2', $config['name'] ?? ''),
            (new Link(
                new Icon('edit'),
                Url::fromPath('notifications/event-rule/edit', [
                    'id' => -1
                ]),
                ['class' => 'control-button']
            ))->openInModal()
        ]);

        $this->addControl($eventRuleForm);
        $this->addControl($eventRuleConfigSubmitButton);
        $this->addContent($eventRuleConfig);
    }

    public function searchEditorAction(): void
    {
        /** @var string $ruleId */
        $ruleId = $this->params->shiftRequired('id');

        /** @var array<string, mixed>|null $eventRule */
        $eventRule = $this->sessionNamespace->get($ruleId);

        if ($eventRule === null) {
            $eventRule = ['id' => '-1'];
        }

        $editor = new SearchEditor();

        /** @var string $objectFilter */
        $objectFilter = $eventRule['object_filter'] ?? '';
        $editor->setQueryString($objectFilter)
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->setSuggestionUrl(Links::ruleFilterSuggestionUrl($ruleId));

        $editor->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) use ($ruleId, $eventRule) {
            $eventRule['object_filter'] = self::createFilterString($form->getFilter());

            $this->sessionNamespace->set($ruleId, $eventRule);
            $this->getResponse()
                ->setHeader('X-Icinga-Container', '_self')
                ->redirectAndExit(
                    Url::fromPath(
                        'notifications/event-rules/add',
                        ['id' => $ruleId]
                    )
                );
        });

        $editor->handleRequest($this->getServerRequest());

        $this->getDocument()->addHtml($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    /**
     * Create filter string from the given filter rule
     *
     * @param Filter\Rule $filters
     *
     * @return ?string
     */
    public static function createFilterString(Filter\Rule $filters): ?string
    {
        if ($filters instanceof Filter\Chain) {
            foreach ($filters as $filter) {
                self::createFilterString($filter);
            }
        } elseif ($filters instanceof Filter\Condition && empty($filters->getValue())) {
            $filters->setValue(true);
        }

        $filterStr = QueryString::render($filters);

        return $filterStr !== '' ? rawurldecode($filterStr) : null;
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
                    'label'      => $this->translate('Schedules'),
                    'url'        => Links::schedules(),
                    'baseTarget' => '_main'
                ])->add('event-rules', [
                    'label'      => $this->translate('Event Rules'),
                    'url'        => Links::eventRules(),
                    'baseTarget' => '_main'
                ])->add('contacts', [
                    'label'      => $this->translate('Contacts'),
                    'url'        => Links::contacts(),
                    'baseTarget' => '_main'
                ])->add('contact-groups', [
                    'label'      => $this->translate('Contact Groups'),
                    'url'        => Links::contactGroups(),
                    'baseTarget' => '_main'
                ]);
        }

        return parent::getTabs();
    }
}
