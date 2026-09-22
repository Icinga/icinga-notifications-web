<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Forms\RuleFilterForm;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Repository\EscalationRuleRepository;
use Icinga\Module\Notifications\Widget\EscalationRule;
use Icinga\Web\Notification;
use Icinga\Web\Session;
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

class EventRuleController extends CompatController
{
    use Auth;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rules');
    }

    public function indexAction(): void
    {
        $rule = (new EscalationRuleRepository(Database::get()))
            ->find((int) $this->params->getRequired('id'));
        if ($rule === null) {
            $this->httpNotFound($this->translate('Rule not found'));
        }

        $this->getTabs()->disableLegacyExtensions();
        $this->addTitleTab(sprintf($this->translate('Event Rule: %s'), $rule->name));

        $this->addControl(Html::tag('div', ['class' => 'event-rule-controls'], [
            Html::tag('div', ['class' => 'event-rule-form'], [
                Html::tag('h2', $rule->name),
                (new Link(
                    new Icon('edit'),
                    Url::fromPath('notifications/event-rule/edit', ['id' => $rule->id]),
                    ['class' => 'control-button']
                ))->openInModal()
            ])
        ]));

        $this->addContent(new EscalationRule($rule));
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

                Notification::success(sprintf(
                    $this->translate('Updated filter for escalation rule "%s"'),
                    $rule->name
                ));
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
                    $ruleName = Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository(Database::get()))->delete($rule->id)
                    )->name;

                    Notification::success(sprintf(
                        $this->translate('Deleted escalation rule "%s"'),
                        $ruleName
                    ));
                    $this->switchToSingleColumnLayout();
                } elseif ($form->hasBeenDuplicated()) {
                    $ruleId = Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository(Database::get()))->duplicate($rule)
                    );

                    Notification::success(sprintf(
                        $this->translate('Created escalation rule "%s"'),
                        $rule->name
                    ));
                    $this->sendExtraUpdates(['#col1']);
                    $this->redirectNow(Links::eventRule($ruleId));
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new EscalationRuleRepository($db))->update($rule)
                    );

                    Notification::success(sprintf(
                        $this->translate('Updated escalation rule "%s"'),
                        $rule->name
                    ));
                    $this->closeModalAndRefreshRemainingViews(Links::eventRule($rule->id));
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
