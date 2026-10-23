<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Exception;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Forms\RuleFilterForm;
use Icinga\Module\Notifications\Hook\V2\V1FilterMigrationHook;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Repository\RuleRepository;
use Icinga\Module\Notifications\Util\RuleSerializer;
use Icinga\Module\Notifications\Widget\EventRule;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use InvalidArgumentException;
use ipl\Html\Contract\Form;
use ipl\Html\Html;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CalloutType;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\Url;
use ipl\Web\Widget\Callout;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleController extends CompatController
{
    use Auth;

    public function init(): void
    {
        $this->assertPermission('notifications/config/event-rules');

        parent::init();
    }

    public function indexAction(): void
    {
        $rule = (new RuleRepository(Database::get()))
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

        $this->addContent(new EventRule($rule));
    }

    public function searchEditorAction(): void
    {
        $this->setTitle($this->translate('Adjust Filter'));

        $form = (new RuleFilterForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, RuleFilterForm $form) {
                $rule = (new RuleRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($rule === null) {
                    $this->httpNotFound($this->translate('Rule not found'));
                }

                $form->setRule($rule);
            })->on(Form::ON_SUBMIT, function (RuleFilterForm $form) {
                $rule = $form->getRule();

                Database::get()->transaction(
                    fn(Connection $db) => (new RuleRepository(Database::get()))->update($rule)
                );

                Notification::success(sprintf(
                    $this->translate('Updated filter for event rule "%s"'),
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
                $rule = (new RuleRepository(Database::get()))
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
                        fn(Connection $db) => (new RuleRepository(Database::get()))->delete($rule->id)
                    )->name;

                    Notification::success(sprintf(
                        $this->translate('Deleted event rule "%s"'),
                        $ruleName
                    ));
                    $this->switchToSingleColumnLayout();
                } elseif ($form->hasBeenDuplicated()) {
                    $ruleId = Database::get()->transaction(
                        fn(Connection $db) => (new RuleRepository(Database::get()))->duplicate($rule)
                    );

                    Notification::success(sprintf(
                        $this->translate('Created event rule "%s"'),
                        $rule->name
                    ));
                    $this->sendExtraUpdates(['#col1']);
                    $this->redirectNow(Links::eventRule($ruleId));
                } else {
                    Database::get()->transaction(
                        fn(Connection $db) => (new RuleRepository($db))->update($rule)
                    );

                    Notification::success(sprintf(
                        $this->translate('Updated event rule "%s"'),
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

    /**
     * @todo Consider to drop this or re-factor it to a proper migration design.
     */
    public function migrateAction(): void
    {
        $id = $this->params->getRequired('id');

        $rule = (new RuleRepository(Database::get()))
            ->find((int) $id);
        if ($rule === null) {
            $this->httpNotFound($this->translate('Rule not found'));
        }

        $migrationRequired = false;

        try {
            RuleSerializer::decode($rule->object_filter);
        } catch (Exception) {
            $migrationRequired = true;
        }

        if (! $migrationRequired) {
            $this->httpBadRequest('Migration is not required for this rule');
        }

        if (SourceHookLocator::forType($rule->source_type) === null) {
            $this->addContent(new Callout(
                CalloutType::Error,
                sprintf($this->translate(
                    'No source integration available. Either the module supporting sources of type "%s" is not'
                    . ' enabled or you have insufficient privileges. Please contact your system administrator.'
                ), $rule->source_type)
            ));

            return;
        }

        /** @var ?V1FilterMigrationHook $hook */
        $hook = null;
        foreach (Hook::all('notifications/V2/V1FilterMigration') as $candidate) {
            if ($candidate->getSourceType() === $rule->source_type) {
                $hook = $candidate;

                break;
            }
        }

        if ($hook === null) {
            $this->httpNotFound(sprintf(
                $this->translate('No migration hook found for source type "%s"'),
                $rule->source_type
            ));
        }

        try {
            $filter = $hook->migrateFilter($rule->object_filter);
        } catch (InvalidArgumentException $e) {
            Logger::error($e);
            $this->addContent(new Callout(
                CalloutType::Error,
                sprintf(
                    'Failed to prepare migration: %s. The log may provide more information.',
                    $e->getMessage()
                )
            ));

            return;
        }

        $rule->object_filter = null;

        $form = (new RuleFilterForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->addAttributes(['class' => 'rule-filter-migration-form'])
            ->setQueryString(QueryString::render($filter))
            ->setRule($rule)
            ->on(Form::ON_SUBMIT, function (RuleFilterForm $form) {
                $rule = $form->getRule();

                Database::get()->transaction(
                    fn(Connection $db) => (new RuleRepository(Database::get()))->update($rule)
                );

                Notification::success(sprintf(
                    $this->translate('Migrated filter for event rule "%s"'),
                    $rule->name
                ));

                $this->sendExtraUpdates(['#col1']);
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

        $form->prependHtml(new Callout(
            CalloutType::Info,
            $this->translate(
                'The filter for this rule is in an old format and needs to be migrated.'
                . ' Please review the filter below and submit the form to complete the migration.'
            )
        ));

        $this->addContent($form);

        $this->getTabs()->disableLegacyExtensions();
    }
}
