<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
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
use ipl\Web\Widget\EmptyStateBar;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleController extends CompatController
{
    use Auth;

    /** @var Session\SessionNamespace */
    private Session\SessionNamespace $sessionNamespace;

    public function init()
    {
        $this->sessionNamespace = Session::getSession()->getNamespace('notifications');
        $this->assertPermission('notifications/config/event-rule');
    }

    public function indexAction(): void
    {
        $this->addTitleTab($this->translate('Event Rule'));
        $this->content->addAttributes(['class' => 'event-rule-detail']);

        $ruleId = (int) $this->params->getRequired('id');
        $config = $this->sessionNamespace->get($ruleId);

        $fromCache = true;
        if ($config === null) {
            $config = $this->fromDb($ruleId);
            $fromCache = false;
        }

        $eventRuleConfig = new EventRuleConfigForm(
            $config,
            Url::fromPath('notifications/event-rule/search-editor', ['id' => $ruleId])
        );

        $eventRuleConfig
            ->populate($config)
            ->on(Form::ON_SUCCESS, function (EventRuleConfigForm $form) use ($ruleId, $config) {
                $insertId = $form->addOrUpdateRule($ruleId, $config);
                $this->sessionNamespace->delete($ruleId);
                Notification::success(sprintf(
                    $this->translate('Successfully saved event rule %s'),
                    $config['name']
                ));
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow(Links::eventRule($insertId));
            })
            ->on(EventRuleConfigForm::ON_SENT, function (EventRuleConfigForm $form) use ($ruleId, $config) {
                if ($form->hasBeenRemoved()) {
                    $form->removeRule($ruleId);
                    $this->sessionNamespace->delete($ruleId);
                    Notification::success(sprintf(
                        $this->translate('Successfully deleted event rule %s'),
                        $config['name']
                    ));
                    $this->redirectNow(Links::eventRules());
                } elseif ($form->hasBeenDiscarded()) {
                    $this->sessionNamespace->delete($ruleId);
                    Notification::success(sprintf(
                        $this->translate('Successfully discarded changes to event rule %s'),
                        $config['name']
                    ));

                    if ($ruleId === -1) {
                        $this->switchToSingleColumnLayout();
                    } else {
                        $this->redirectNow(Links::eventRule($ruleId));
                    }
                }
            })
            ->on(EventRuleConfigForm::ON_CHANGE, function (EventRuleConfigForm $form) use ($ruleId, $config) {
                $formValues = $form->getValues();
                $config = array_merge($config, $formValues);
                $config['rule_escalation'] = $formValues['rule_escalation'];
                $this->sessionNamespace->set($ruleId, $config);
            })
            ->handleRequest($this->getServerRequest());

        $discardChangesButton = null;
        if ($fromCache) {
            $this->addContent(new EmptyStateBar($this->translate('There are unsaved changes.')));
            $discardChangesButton = new SubmitButtonElement(
                'discard_changes',
                [
                    'label'          => $this->translate('Discard Changes'),
                    'form'           => 'event-rule-config-form',
                    'class'          => 'btn-discard-changes',
                    'formnovalidate' => true
                ]
            );
        }

        $buttonsWrapper = new HtmlElement('div', Attributes::create(['class' => ['icinga-controls', 'save-config']]));
        $eventRuleConfigSubmitButton = new SubmitButtonElement(
            'save',
            [
                'label'    => $this->translate('Save'),
                'form'     => 'event-rule-config-form',
                'disabled' => $fromCache
            ]
        );

        $deleteButton = null;
        if ($ruleId !== -1) {
            $deleteButton = new SubmitButtonElement(
                'delete',
                [
                    'label'          => $this->translate('Delete'),
                    'form'           => 'event-rule-config-form',
                    'class'          => 'btn-remove',
                    'formnovalidate' => true
                ]
            );
        }

        $buttonsWrapper->add([$eventRuleConfigSubmitButton, $discardChangesButton, $deleteButton]);

        $eventRuleForm = Html::tag('div', ['class' => 'event-rule-form'], [
            Html::tag('h2', $config['name']),
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
            ->columns(['id', 'name', 'object_filter'])
            ->filter(Filter::equal('id', $ruleId));

        $rule = $query->first();
        if ($rule === null) {
            $this->httpNotFound($this->translate('Rule not found'));
        }

        $config = iterator_to_array($rule);

        $ruleEscalations = $rule
            ->rule_escalation
            ->columns(['id', 'position', 'condition']);

        foreach ($ruleEscalations as $re) {
            foreach ($re as $k => $v) {
                if ($k !== 'position') {
                    $config[$re->getTableName()][$re->position][$k] = (string) $v;
                }
            }

            $escalationRecipients = $re
                ->rule_escalation_recipient
                ->columns(['id', 'position', 'contact_id', 'contactgroup_id', 'schedule_id', 'channel_id']);

            foreach ($escalationRecipients as $recipient) {
                $recipientData = [];

                foreach ($recipient as $k => $v) {
                    if ($v !== null && in_array($k, ['contact_id', 'contactgroup_id', 'schedule_id'])) {
                        $recipientData[$k] = (string) $v;
                    } elseif (in_array($k, ['id', 'channel_id'])) {
                        $recipientData[$k] = $v ? (string) $v : null;
                    }
                }

                $config[$re->getTableName()][$re->position]['recipients'][] = $recipientData;
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
        $ruleId = (int) $this->params->shiftRequired('id');
        $config = $this->sessionNamespace->get($ruleId) ?? $this->fromDb($ruleId);

        $editor = new SearchEditor();

        $editor->setQueryString($config['object_filter'] ?? '')
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->setSuggestionUrl(
                Url::fromPath('notifications/event-rule/complete', [
                    'id' => $ruleId,
                    '_disableLayout' => true,
                    'showCompact' => true
                ])
            );

        $editor->on(Form::ON_SUCCESS, function (SearchEditor $form) use ($ruleId, $config) {
            $config['object_filter'] = $this->createFilterString($form->getFilter());
            $this->sessionNamespace->set($ruleId, $config);
            $this->closeModalAndRefreshRelatedView(Url::fromPath(
                'notifications/event-rule',
                ['id' => $ruleId]
            ));
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
    private function createFilterString(Filter\Rule $filters): ?string
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

    public function editAction(): void
    {
        $ruleId = (int) $this->params->getRequired('id');
        $config = $this->sessionNamespace->get($ruleId) ?? $this->fromDb($ruleId);

        $eventRuleForm = (new EventRuleForm())
            ->populate($config)
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUCCESS, function ($form) use ($ruleId, $config) {
                $config['name'] = $form->getValue('name');
                $this->sessionNamespace->set($ruleId, $config);
                $this->closeModalAndRefreshRemainingViews(Links::eventRule($ruleId));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Edit Event Rule'));

        $this->addContent($eventRuleForm);
    }
}
