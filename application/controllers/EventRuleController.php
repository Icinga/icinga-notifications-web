<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Web\Control\SearchBar\ExtraTagSuggestions;
use Icinga\Web\Notification;
use Icinga\Web\Session;
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

    /** @var Session\SessionNamespace */
    private $sessionNamespace;

    /** @var ?string Event rule config filter */
    protected $filter;

    public function init()
    {
        $this->sessionNamespace = Session::getSession()->getNamespace('notifications');
    }

    public function indexAction(): void
    {
        $this->assertPermission('notifications/config/event-rule');

        $this->addTitleTab(t('Event Rule'));
        $this->controls->addAttributes(['class' => 'event-rule-detail']);

        /** @var string $ruleId */
        $ruleId = $this->params->getRequired('id');
        /** @var array<string, mixed>|null $configValues */
        $configValues = $this->sessionNamespace->get($ruleId);
        $this->controls->addAttributes(['class' => 'event-rule-detail']);

        $disableSave = false;
        if ($configValues === null) {
            $configValues = $this->fromDb((int) $ruleId);
            $disableSave = true;
        }

        $eventRuleConfig = (new EventRuleConfigForm(
            $configValues,
            Url::fromPath(
                'notifications/event-rule/search-editor',
                ['id' => $ruleId]
            )
        ))->populate($configValues);
        $eventRuleConfig
            ->on(Form::ON_SUCCESS, function (EventRuleConfigForm $form) use ($ruleId, $configValues) {
                $form->addOrUpdateRule($ruleId, $configValues);
                $this->sessionNamespace->delete($ruleId);
                Notification::success((sprintf(t('Successfully saved event rule %s'), $configValues['name'])));
                $this->redirectNow(Links::eventRule((int) $ruleId));
            })
            ->on(EventRuleConfigForm::ON_DELETE, function (EventRuleConfigForm $form) use ($ruleId, $configValues) {
                $form->removeRule((int) $ruleId);
                $this->sessionNamespace->delete($ruleId);
                Notification::success(sprintf(t('Successfully deleted event rule %s'), $configValues['name']));
                $this->redirectNow('__CLOSE__');
            })
            ->on(EventRuleConfigForm::ON_DISCARD, function () use ($ruleId, $configValues) {
                $this->sessionNamespace->delete($ruleId);
                Notification::success(
                    sprintf(
                        t('Successfully discarded changes to event rule %s'),
                        $configValues['name']
                    )
                );
                $this->redirectNow(Links::eventRule((int) $ruleId));
            })
            ->on(EventRuleConfigForm::ON_CHANGE, function (EventRuleConfigForm $form) use ($ruleId, $configValues) {
                $configValues = array_merge($configValues, $form->getValues());
                $configValues['rule_escalation'] = $form->getValues()['rule_escalation'];
                $this->sessionNamespace->set($ruleId, $configValues);
            })
            ->handleRequest($this->getServerRequest());

        /** @var array<string, mixed> $cache */
        $cache = $this->sessionNamespace->get($ruleId);
        $discardChangesButton = null;
        if ($cache !== null) {
            $this->addContent(Html::tag('div', ['class' => 'cache-notice'], t('There are unsaved changes.')));
            $discardChangesButton = (new SubmitButtonElement(
                'discard_changes',
                [
                    'label'          => t('Discard Changes'),
                    'form'           => 'event-rule-config-form',
                    'class'          => 'btn-discard-changes',
                    'formnovalidate' => true,
                ]
            ));
            $disableSave = false;
        }


        $buttonsWrapper = new HtmlElement('div', Attributes::create(['class' => ['icinga-controls', 'save-config']]));
        $eventRuleConfigSubmitButton = (new SubmitButtonElement(
            'save',
            [
                'label'    => t('Save'),
                'form'     => 'event-rule-config-form',
                'disabled' => $disableSave
            ]
        ));
        $deleteButton = (new SubmitButtonElement(
            'delete',
            [
                'label'          => t('Delete'),
                'form'           => 'event-rule-config-form',
                'class'          => 'btn-remove',
                'formnovalidate' => true
            ]
        ));

        $buttonsWrapper->add(
            [$eventRuleConfigSubmitButton, $discardChangesButton, $deleteButton]
        );

        if ($ruleId > 0) {
            $incidents = Incident::on(Database::get())
                ->with('rule')
                ->filter(Filter::equal('rule.id', $ruleId));

            if ($incidents->count() > 0) {
                $deleteButton->addAttributes(['disabled' => true]);
            }
        }

        $eventRuleForm = Html::tag('div', ['class' => 'event-rule-form'], [
            Html::tag('h2', $configValues['name'] ?? ''),
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

        foreach ($rule->rule_escalation as $re) {
            foreach ($re as $k => $v) {
                if (in_array($k, ['id', 'condition'])) {
                    /** @var int|string|null $v */
                    $config[$re->getTableName()][$re->position][$k] = (string) $v;
                }
            }

            foreach ($re->rule_escalation_recipient as $recipient) {
                $requiredValues = [];

                foreach ($recipient as $k => $v) {
                    if (in_array($k, ['contact_id', 'contactgroup_id', 'schedule_id']) && $v !== null) {
                        $requiredValues[$k] = (string) $v;
                    } elseif (in_array($k, ['id', 'channel_id'])) {
                        $requiredValues[$k] = $v ? (string) $v : null;
                    }
                }

                $config[$re->getTableName()][$re->position]['recipients'][] = $requiredValues;
            }
        }

        $config['showSearchbar'] = ! empty($config['object_filter']);

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

        $eventRule = $this->sessionNamespace->get($ruleId);

        if ($eventRule === null) {
            $eventRule = $this->fromDb((int) $ruleId);
        }

        $editor = new SearchEditor();

        /** @var string $objectFilter */
        $objectFilter = $eventRule['object_filter'] ?? '';
        $editor->setQueryString($objectFilter);
        $editor->setAction(Url::fromRequest()->getAbsoluteUrl());
        $editor->setSuggestionUrl(
            Url::fromPath(
                "notifications/event-rule/complete",
                ['_disableLayout' => true, 'showCompact' => true, 'id' => Url::fromRequest()->getParams()->get('id')]
            )
        );

        $editor->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) use ($ruleId, $eventRule) {
            $filter = self::createFilterString($form->getFilter());
            $eventRule['object_filter'] = $filter;
            $this->sessionNamespace->set($ruleId, $eventRule);
            $this->getResponse()
                ->setHeader('X-Icinga-Container', '_self')
                ->redirectAndExit(
                    Url::fromPath(
                        'notifications/event-rule',
                        ['id' => $ruleId]
                    )
                );
        });

        $editor->handleRequest($this->getServerRequest());

        $this->getDocument()->add($editor);
        $this->setTitle($this->translate('Adjust Filter'));
    }

    /**
     * Create filter string from the given filter rule
     *
     * @param Filter\Rule $filters
     *
     * @return string
     */
    public static function createFilterString(Filter\Rule $filters): string
    {
        if ($filters instanceof Filter\Chain) {
            foreach ($filters as $filter) {
                self::createFilterString($filter);
            }
        } elseif ($filters instanceof Filter\Condition && empty($filters->getValue())) {
            $filters->setValue(true);
        }

        $filterStr = QueryString::render($filters);

        return ! empty($filterStr) ? $filterStr : '';
    }

    public function editAction(): void
    {
        /** @var string $ruleId */
        $ruleId = $this->params->getRequired('id');

        if ($ruleId === '-1') {
            $config = ['id' => $ruleId];
        } else {
            $config = $this->fromDb((int) $ruleId);
        }

        $eventRuleForm = (new EventRuleForm())
            ->populate($config)
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUCCESS, function ($form) use ($ruleId, $config) {
                $config['name'] = $form->getValue('name');
                $config['is_active'] = $form->getValue('is_active');
                $params = [];
                if ($ruleId === '-1') {
                    $params = $config;
                } else {
                    $params['id'] = $ruleId;
                }

                if ($ruleId === '-1') {
                    $redirectUrl = Url::fromPath('notifications/event-rules/add', $params);
                } else {
                    $redirectUrl = Url::fromPath('notifications/event-rule', $params);
                    $this->sendExtraUpdates(['#col1']);
                }

                $this->sessionNamespace->set($ruleId, $config);
                $this->getResponse()->setHeader('X-Icinga-Container', 'col2');
                $this->redirectNow($redirectUrl);
            })->handleRequest($this->getServerRequest());

        if ($ruleId === '-1') {
            $this->setTitle($this->translate('New Event Rule'));
        } else {
            $this->setTitle($this->translate('Edit Event Rule'));
        }

        $this->addContent($eventRuleForm);
    }
}
