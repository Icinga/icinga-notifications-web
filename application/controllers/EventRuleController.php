<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Data\NotificationConfigProvider;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Forms\RuleFilterForm;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Repository\EscalationRuleRepository;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Html;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use Psr\Http\Message\ServerRequestInterface;

class EventRuleController extends CompatController
{
    use Auth;

    private Session\SessionNamespace $session;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rules');
        $this->session = Session::getSession()->getNamespace('notifications.event-rule');
    }

    public function indexAction(): void
    {
        $this->controls->addAttributes(Attributes::create(['class' => 'event-rule-detail']));
        $this->content->addAttributes(Attributes::create(['class' => 'event-rule-detail']));
        $this->getTabs()->disableLegacyExtensions();

        $ruleId = (int) $this->params->getRequired('id');

        $multiPartUpdate = false;
        $eventRuleConfig = (new EventRuleConfigForm(
            new NotificationConfigProvider(),
            Url::fromPath('notifications/event-rule/search-editor', ['id' => $ruleId])
        ))->setCsrfCounterMeasureId(Session::getSession()->getId());

        $eventRuleConfig
            ->on(Form::ON_SUBMIT, function (EventRuleConfigForm $form) use ($ruleId) {
                $rule = $form->getRule();

                if ($ruleId === -1) {
                    $ruleId = Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository($db))->create($rule)
                    );
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository($db))->update($rule)
                    );
                }

                Notification::success(sprintf(
                    $this->translate('Successfully saved event rule %s'),
                    $rule->name
                ));
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow(Links::eventRule($ruleId));
            })
            ->on(Form::ON_SENT, function (EventRuleConfigForm $form) use ($ruleId) {
                if ($form->hasBeenRemoved()) {
                    Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository($db))->delete($ruleId)
                    );
                    Notification::success(sprintf(
                        $this->translate('Successfully deleted event rule %s'),
                        $form->getValue('name')
                    ));
                    $this->switchToSingleColumnLayout();
                }
            })
            ->on(Form::ON_REQUEST, function (
                ServerRequestInterface $request,
                EventRuleConfigForm $form
            ) use (
                $ruleId,
                &$multiPartUpdate
            ) {
                $nameOnly = (bool) $this->params->shift('_nameOnly');
                $filterOnly = (bool) $this->params->shift('_filterOnly');

                if ($nameOnly || $filterOnly) {
                    $multiPartUpdate = true;

                    if ($nameOnly) {
                        $this->addTitleTab(sprintf(
                            $this->translate('Event Rule: %s'),
                            $this->session->get('name')
                        ));

                        $this->addPart($this->tabs);
                        $this->addPart($form->prepareObjectFilterUpdate($this->session->get('object_filter')));
                        $this->addPart($form->prepareConfigUpdate(
                            $this->session->get('name'),
                            $this->session->get('source_type')
                        ));
                        $this->addPart(Html::tag('div', ['id' => 'event-rule-config-name'], [
                            Html::tag('h2', $this->session->get('name')),
                            (new Link(
                                new Icon('edit'),
                                Url::fromPath('notifications/event-rule/edit', ['id' => $ruleId]),
                                ['class' => 'control-button']
                            ))->openInModal()
                        ]));
                    } else {
                        $this->addPart($form->prepareConfigUpdate(
                            $this->session->get('name'),
                            $this->session->get('source_type')
                        ));
                        $this->addPart($form->prepareObjectFilterUpdate($this->session->get('object_filter')));
                    }

                    $this->getResponse()->setHeader('X-Icinga-Location-Query', $this->params->toString());
                } elseif ($ruleId !== -1) {
                    $rule = (new EscalationRuleRepository(Database::get()))->find($ruleId);
                    if ($rule === null) {
                        $this->httpNotFound(t('Rule not found'));
                    }

                    $form->setRule($rule);

                    $this->session->set('name', $rule->name);
                    $this->session->set('source_type', $rule->source_type);
                    $this->session->set('object_filter', $rule->object_filter ?? '');
                } else {
                    $name = $this->params->getRequired('name');
                    $source = $this->params->getRequired('source_type');
                    $form->populate(['name' => $name, 'source_type' => $source]);

                    $this->session->set('name', $name);
                    $this->session->set('source_type', $source);
                    $this->session->set('object_filter', '');
                }
            })
            ->handleRequest($this->getServerRequest());

        if ($multiPartUpdate) {
            return;
        }

        $this->addControl(Html::tag('div', ['class' => 'event-rule-and-save-forms'], [
            Html::tag('div', ['class' => 'event-rule-form', 'id' => 'event-rule-config-name'], [
                Html::tag('h2', $eventRuleConfig->getValue('name')),
                (new Link(
                    new Icon('edit'),
                    Url::fromPath('notifications/event-rule/edit', ['id' => $ruleId]),
                    ['class' => 'control-button']
                ))->openInModal()
            ]),
            Html::tag(
                'div',
                ['id' => 'save-config', 'class' => 'icinga-controls'],
                $eventRuleConfig->createExternalSubmitButtons()
            )
        ]));

        $this->addTitleTab(sprintf($this->translate('Event Rule: %s'), $eventRuleConfig->getValue('name')));
        $this->addContent($eventRuleConfig);
    }

    public function searchEditorAction(): void
    {
        $this->setTitle($this->translate('Adjust Filter'));

        $form = (new RuleFilterForm())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, RuleFilterForm $form) {
                $rule = (new EscalationRuleRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($rule === null) {
                    $this->httpNotFound($this->translate('Rule not found'));
                }

                $form->setRule($rule);
            })->on(Form::ON_SUBMIT, function (RuleFilterForm $form) {
                $rule = $form->getRule();

                Database::get()->transaction(
                    fn(Connection $db) => (new EscalationRuleRepository(Database::get()))->update($rule)
                );

                $this->redirectNow(Links::eventRule($rule->id));
            })->handleRequest($this->getServerRequest());

        $form->setSuggestionUrl(Url::fromPath(
            'notifications/event-rule/suggest',
            [
                'source_type' => $form->getValue('source_type'),
                '_disableLayout' => true,
                'showCompact' => true
            ]
        ));

        $this->getDocument()->addHtml($form);
    }

    public function suggestAction(): void
    {
        $hook = SourceHookLocator::forType($this->params->getRequired('source_type'));
        $requestData = SearchSuggestions::parseRequest($this->getServerRequest()) ?? [];

        $type = $requestData['term']['type'] ?? null;
        $label = $requestData['term']['label'] ?? '';
        $failureMessage = null;

        $provider = [];
        if ($type === 'column' && $hook !== null) {
            $provider = $hook->getColumnSuggestions($label);
        } elseif ($type === 'value') {
            $column = $requestData['column'] ?? null;
            if ($column === null || $column === SearchEditor::FAKE_COLUMN) {
                $failureMessage = $this->translate('Missing column name');
            } elseif ($hook !== null) {
                /** @var Filter\Chain $searchFilter */
                $searchFilter = QueryString::parse($requestData['searchFilter'] ?? '');
                $provider = $hook->getValueSuggestions($column, $label, $searchFilter);
            }
        }

        $suggestions = (new SearchSuggestions($provider))
            ->setSearchTerm($label)
            ->setOriginalSearchValue($requestData['term']['search'] ?? '')
            ->showFailureMessage($failureMessage);

        if ($type === 'column') {
            $suggestions->setGroupingCallback(fn ($x) => $x['group']);
        }

        $this->getDocument()->addHtml($suggestions);
    }

    public function editAction(): void
    {
        $this->setTitle($this->translate('Edit Event Rule'));

        (new EventRuleForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAvailableSourceTypes(
                Database::get()->fetchCol(
                    Source::on(Database::get())->columns(['type'])->assembleSelect()->distinct()
                )
            )
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, EventRuleForm $form) {
                $rule = (new EscalationRuleRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($rule === null) {
                    $this->httpNotFound($this->translate('Rule not found'));
                }

                $form->setRule($rule);

                $this->addContent($form);
            })
            ->on(Form::ON_SUBMIT, function (EventRuleForm $form) {
                $rule = $form->getRule();

                if ($form->hasBeenDeleted()) {
                    Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository(Database::get()))->delete($rule->id)
                    );

                    $this->switchToSingleColumnLayout();
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository($db))->update($rule)
                    );

                    $this->sendExtraUpdates(['#col1']);
                    $this->closeModalAndRefreshRelatedView(Links::eventRule($rule->id));
                }
            })->on(Form::ON_ERROR, function ($_, EventRuleForm $form) {
                // TODO: I feel this should be part of CompatForm or CompatController (e.g. $this->sendForm())
                $this->addPart($form, $this->content->getAttribute('id')->getValue());
            })->on(Form::ON_SENT, function (EventRuleForm $form) {
                if (! $form->hasBeenSubmitted()) {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })->handleRequest($this->getServerRequest());
    }
}
