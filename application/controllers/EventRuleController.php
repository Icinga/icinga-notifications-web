<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\EventRuleConfigFilter;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Web\Control\SearchBar\ExtraTagSuggestions;
use Icinga\Web\Notification;
use ipl\Html\Attributes;
use ipl\Html\Form;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleController extends CompatController
{
    use Auth;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rule');
    }

    public function indexAction(): void
    {
        // Add an empty container and set it as the X-Icinga-Container when sending extra updates
        // from the modal for filter or event rule
        $this->addContent(new HtmlElement(
            'div',
            Attributes::create(['class' => 'container', 'id' => 'dummy-event-rule-container'])
        ));

        $this->addTitleTab(t('Event Rule'));
        $this->controls->addAttributes(['class' => 'event-rule-detail']);
        $ruleId = $this->params->getRequired('id');

        $eventRuleConfigValues = $this->fromDb((int) $ruleId);
        $filter = &$eventRuleConfigValues['object_filter']; // Assignment by reference to is used as search editor is a
                                                            // different form and the config must have the updated
                                                            // object_filter as soon as the search editor is closed

        if ($this->getRequest()->isPost()) {
            if ($this->getRequest()->has('searchbar')) {
                $filter = $this->getRequest()->get('searchbar');
            } else {
                $filter = null;
            }
        }

        $eventRuleConfig = (new EventRuleConfigForm(
            $eventRuleConfigValues,
            Url::fromPath(
                'notifications/event-rule/search-editor',
                ['id' => $ruleId, 'object_filter' => $filter]
            )
        ))
            ->populate($eventRuleConfigValues)
            ->on(Form::ON_SUCCESS, function (EventRuleConfigForm $form) use ($ruleId, $eventRuleConfigValues) {
                $diff = $form->getChanges();
                if (empty($diff)) {
                    return;
                }

                $form->addOrUpdateRule($ruleId, $diff);
                Notification::success(sprintf(
                    t('Successfully saved event rule %s'),
                    $eventRuleConfigValues['name']
                ));

                $this->redirectNow(Links::eventRule((int) $ruleId));
            })
            ->on(
                EventRuleConfigForm::ON_DELETE,
                function (EventRuleConfigForm $form) use ($ruleId, $eventRuleConfigValues) {
                    $form->removeRule((int) $ruleId);
                    Notification::success(
                        sprintf(t('Successfully deleted event rule %s'), $eventRuleConfigValues['name'])
                    );
                    $this->redirectNow('__CLOSE__');
                }
            )
            ->handleRequest($this->getServerRequest());

        $buttonsWrapper = new HtmlElement('div', Attributes::create(['class' => ['icinga-controls', 'save-config']]));
        $eventRuleConfigSubmitButton = new SubmitButtonElement(
            'save',
            [
                'label'    => t('Save'),
                'form'     => 'event-rule-config-form',
            ]
        );

        $deleteButton = new SubmitButtonElement(
            'delete',
            [
                'label'          => t('Delete'),
                'form'           => 'event-rule-config-form',
                'class'          => 'btn-remove',
                'formnovalidate' => true
            ]
        );

        $buttonsWrapper->addHtml($eventRuleConfigSubmitButton, $deleteButton);

        if ($ruleId > 0) {
            $incidentCount = Incident::on(Database::get())
                ->with('rule')
                ->filter(Filter::equal('rule.id', $ruleId))
                ->count();

            if ($incidentCount) {
                $deleteButton->addAttributes([
                    'disabled' => true,
                    'title'    => t('There are active incidents for this event rule and hence cannot be removed')
                ]);
            }
        }

        $eventRuleForm = Html::tag('div', ['class' => 'event-rule-form', 'id' => 'event-rule-form'], [
            Html::tag('h2', $eventRuleConfigValues['name']),
            (new Link(
                new Icon('edit'),
                Url::fromPath('notifications/event-rule/edit', [
                    'id' => $ruleId
                ]),
                ['class' => 'control-button']
            ))->openInModal()
        ]);

        $this->addControl($eventRuleForm);
        $this->addControl($buttonsWrapper);
        $this->addContent($eventRuleConfig);
    }

    public function ruleAction(): void
    {
        /** @var int $ruleId */
        $ruleId = $this->params->getRequired('id');
        $query = Rule::on(Database::get())
            ->withoutColumns('timeperiod_id')
            ->filter(Filter::equal('id', $ruleId));

        $rule = $query->first();

        $this->getDocument()->add([
            Html::tag('h2', $rule->name),
            (new Link(
                new Icon('edit'),
                Url::fromPath('notifications/event-rule/edit', [
                    'id' => $ruleId
                ]),
                ['class' => 'control-button']
            ))->openInModal()
        ]);
    }

    public function configFilterAction(): void
    {
        $ruleId = $this->params->getRequired('id');
        $objectFilter = $this->params->get('object_filter');
        $eventRuleFilterFieldset = new EventRuleConfigFilter(
            Url::fromPath(
                'notifications/event-rule/search-editor',
                ['id' => $ruleId, 'object_filter' => $objectFilter]
            ),
            $objectFilter
        );

        if (! $objectFilter) {
            $eventRuleFilterFieldset->getAttributes()->add('class', 'empty-filter');
        }

        $this->getDocument()->addHtml($eventRuleFilterFieldset);
    }

    /**
     * Create config from db
     *
     * @param int $ruleId
     *
     * @return array
     */
    public function fromDb(int $ruleId): array
    {
        $query = Rule::on(Database::get())
            ->withoutColumns('timeperiod_id')
            ->filter(Filter::equal('id', $ruleId));

        $rule = $query->first();
        if ($rule === null) {
            $this->httpNotFound(t('Rule not found'));
        }

        $config = iterator_to_array($rule);
        $config['object_filter'] = $config['object_filter'] ?? null;

        foreach ($rule->rule_escalation as $re) {
            foreach ($re as $k => $v) {
                if (in_array($k, ['id', 'condition'])) {
                    $config[$re->getTableName()][$re->position][$k] = (string) $v;
                }
            }

            foreach ($re->rule_escalation_recipient as $recipient) {
                $requiredValues = [];

                foreach ($recipient as $k => $v) {
                    if ($v !== null && in_array($k, ['contact_id', 'contactgroup_id', 'schedule_id'])) {
                        $requiredValues[$k] = (string) $v;
                    } elseif (in_array($k, ['id', 'channel_id'])) {
                        $requiredValues[$k] = $v ? (string) $v : null;
                    }
                }

                $config[$re->getTableName()][$re->position]['recipients'][] = $requiredValues;
            }
        }

        return $config;
    }

    /**
     * completeAction for Object Extra Tags
     *
     * @return void
     */
    public function completeAction(): void
    {
        $suggestions = new ExtraTagSuggestions();
        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->add($suggestions);
    }

    /**
     * searchEditorAction for Object Extra Tags
     *
     * @return void
     *
     * @throws \Icinga\Exception\MissingParameterException
     */
    public function searchEditorAction(): void
    {
        /** @var string $ruleId */
        $ruleId = $this->params->shiftRequired('id');
        $editor = new SearchEditor();

        /** @var string $objectFilter */
        $objectFilter = $this->params->shift('object_filter', '');
        $editor->setQueryString($objectFilter)
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->setSuggestionUrl(
                Links::ruleFilterSuggestionUrl($ruleId)->addParams(['_disableLayout' => true, 'showCompact' => true])
            );

        $editor->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) use ($ruleId) {
            $this->sendExtraUpdates(
                [
                    '#filter-wrapper' => Url::fromPath(
                        'notifications/event-rule/config-filter',
                        ['id' => $ruleId, 'object_filter' => self::createFilterString($form->getFilter())]
                    )
                ]
            );
            $this->getResponse()
                ->setHeader('X-Icinga-Container', 'dummy-event-rule-container')
                ->redirectAndExit('__CLOSE__');
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
                /** @var Filter\Condition $filter */
                $filter->setValue(true);
            }
        } elseif ($filters instanceof Filter\Condition && empty($filters->getValue())) {
            $filters->setValue(true);
        }

        $filterStr = QueryString::render($filters);

        return $filterStr !== '' ? rawurldecode($filterStr) : null;
    }

    public function addAction(): void
    {
        $this->addTitleTab(t('Add Event Rule'));
        $this->getTabs()->setRefreshUrl(Url::fromRequest());

        // Add an empty container and set it as the X-Icinga-Container when sending extra updates
        // from the modal for filter or event rule
        $this->addContent(Html::tag('div', ['class' => 'container', 'id' => 'dummy-event-rule-container']));

        $this->controls->addAttributes(['class' => 'event-rule-detail']);
        $ruleId = $this->params->get('id');

        if ($this->getRequest()->isPost()) {
            if ($this->getRequest()->has('searchbar')) {
                $eventRule['object_filter'] = $this->getRequest()->get('searchbar');
            } else {
                $eventRule['object_filter'] = null;
            }
        }

        $eventRuleConfigSubmitButton = (new SubmitButtonElement(
            'save',
            [
                'label'          => t('Add Event Rule'),
                'form'           => 'event-rule-config-form'
            ]
        ))->setWrapper(new HtmlElement('div', Attributes::create(['class' => ['icinga-controls', 'save-config']])));

        $eventRuleConfig = new EventRuleConfigForm(
            $eventRule,
            Url::fromPath(
                'notifications/event-rule/search-editor',
                ['id' => $ruleId, 'object_filter' => $eventRule['object_filter']]
            )
        );

        $eventRuleConfig
            ->on(Form::ON_SUCCESS, function (EventRuleConfigForm $form) use ($eventRule) {
                /** @var string $ruleId */
                $ruleId = $eventRule['id'];
                /** @var string $ruleName */
                $ruleName = $eventRule['name'];
                $eventRuleConfig = array_merge($eventRule, $form->getValues());
                $form->addOrUpdateRule($ruleId, $eventRuleConfig);
                Notification::success(sprintf(t('Successfully add event rule %s'), $ruleName));
                $this->redirectNow('__CLOSE__');
            })
            ->handleRequest($this->getServerRequest());

        $eventRuleForm = Html::tag('div', ['class' => 'event-rule-form'], [
            Html::tag('h2', $eventRule['name'] ?? ''),
            Html::tag(
                'div',
                [
                    'class' => 'not-allowed',
                    'title' => $this->translate('Cannot edit event rule until it is saved to database')
                ],
                (new Link(
                    new Icon('edit'),
                    Url::fromPath('notifications/event-rule/edit', [
                        'id' => -1
                    ]),
                    ['class' => ['control-button', 'disabled']]
                ))->openInModal()
            )
        ]);

        $this->addControl($eventRuleForm);
        $this->addControl($eventRuleConfigSubmitButton);
        $this->addContent($eventRuleConfig);
    }

    public function editAction(): void
    {
        /** @var string $ruleId */
        $ruleId = $this->params->getRequired('id');
        $db = Database::get();
        if ($ruleId === '-1') {
            $config = ['id' => $ruleId];
        } else {
            // Casting to array is required as Connection::fetchOne actually returns stdClass and not array
            $config = (array) $db->fetchOne(
                Rule::on($db)->withoutColumns('timeperiod_id')->filter(Filter::equal('id', $ruleId))->assembleSelect()
            );
        }

        $eventRuleForm = (new EventRuleForm())
            ->populate($config)
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUCCESS, function ($form) use ($ruleId, $config, $db) {
                $config['name'] = $form->getValue('name');
                $config['is_active'] = $form->getValue('is_active');

                if ($ruleId === '-1') {
                    $redirectUrl = Url::fromPath('notifications/event-rules/add', ['id' => '-1']);
                    $this->getResponse()->setHeader('X-Icinga-Container', 'col2');
                    $this->redirectNow($redirectUrl);
                } else {
                    $db->update('rule', [
                        'name'          => $config['name'],
                        'is_active'     => $config['is_active'] ?? 'n'
                    ], ['id = ?' => $ruleId]);

                    $this->sendExtraUpdates([
                        '#event-rule-form' =>  Url::fromPath(
                            'notifications/event-rule/rule', ['id' => $ruleId]
                        )->getAbsoluteUrl()
                    ]);

                    $this->getResponse()->setHeader('X-Icinga-Container', 'dummy-event-rule-container')
                        ->redirectAndExit('__CLOSE__');
                }
            })->handleRequest($this->getServerRequest());

        if ($ruleId === '-1') {
            $this->setTitle($this->translate('New Event Rule'));
        } else {
            $this->setTitle($this->translate('Edit Event Rule'));
        }

        $this->addContent($eventRuleForm);
    }
}
