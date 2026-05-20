<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use EmptyIterator;
use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Exception\MissingParameterException;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Forms\EventRuleConfigElements\NotificationConfigProvider;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Util\RuleSerializer;
use Icinga\Module\Notifications\Web\Control\SearchBar\ExtraTagSuggestions;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

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
                if ($ruleId !== -1) {
                    $rule = $this->fetchRule($ruleId);
                } else {
                    $rule = null;
                }

                $ruleId = $form->storeInDatabase(Database::get(), $rule);
                Notification::success(sprintf(
                    $this->translate('Successfully saved event rule %s'),
                    $form->getValue('name')
                ));
                $this->sendExtraUpdates(['#col1']);
                $this->redirectNow(Links::eventRule($ruleId));
            })
            ->on(Form::ON_SENT, function (EventRuleConfigForm $form) use ($ruleId) {
                if ($form->hasBeenRemoved()) {
                    Database::get()->transaction(
                        fn() => $form::removeRule(Database::get(), $this->fetchRule($ruleId))
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
                            $this->session->get('source')
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
                            $this->session->get('source')
                        ));
                        $this->addPart($form->prepareObjectFilterUpdate($this->session->get('object_filter')));
                    }

                    $this->getResponse()->setHeader('X-Icinga-Location-Query', $this->params->toString());
                } elseif ($ruleId !== -1) {
                    $rule = $this->fetchRule($ruleId);

                    $form->load($rule);

                    $this->session->set('name', $rule->name);
                    $this->session->set('source', $rule->source_id);
                    $this->session->set('object_filter', $rule->object_filter ?? '');
                } else {
                    $name = $this->params->getRequired('name');
                    $source = (int) $this->params->getRequired('source');
                    $form->populate(['id' => $ruleId, 'name' => $name, 'source' => $source]);

                    $this->session->set('name', $name);
                    $this->session->set('source', $source);
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
     * @throws MissingParameterException
     */
    public function searchEditorAction(): void
    {
        $ruleId = (int) $this->params->getRequired('id');
        $filter = $this->params->get('object_filter', $this->session->get('object_filter'));
        $hook = $this->resolveSourceHook($ruleId);

        $parsedFilter = null;
        if ($filter) {
            try {
                $parsedFilter = json_decode($filter, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                Logger::error('Failed to parse rule filter configuration: %s (Error: %s)', $filter, $e);
                throw new ConfigurationError($this->translate(
                    'Failed to parse rule filter configuration. Please contact your system administrator.'
                ));
            }

            $version = $parsedFilter['version'] ?? null;
            if ($version !== RuleSerializer::VERSION) {
                Logger::error(
                    'Cannot load filter for rule with id %d: filter version %s is not supported (expected %d)',
                    $ruleId,
                    $version,
                    RuleSerializer::VERSION
                );
                throw new ConfigurationError($this->translate(
                    'Unsupported rule filter version. Please contact your system administrator.'
                ));
            }
        }

        $editor = (new SearchEditor())
            ->setQueryString($parsedFilter['qs'] ?? '')
            ->setSuggestionUrl(
                Url::fromPath(
                    'notifications/event-rule/suggest',
                    ['id' => $ruleId, '_disableLayout' => true, 'showCompact' => true]
                )
            )
            ->setAction(Url::fromRequest()->with('object_filter', $filter)->getAbsoluteUrl())
            ->on(
                SearchEditor::ON_VALIDATE_COLUMN,
                function (Condition $condition) use ($hook) {
                    try {
                        $hook->assertValidCondition($condition);
                    } catch (SearchException $e) {
                        throw $e;
                    } catch (Throwable $e) {
                        Logger::error(
                            'Source hook %s failed to validate filter condition: %s',
                            get_class($hook),
                            $e
                        );

                        throw new SearchException($this->translate(
                            'Failed to validate column. Please contact your system administrator.'
                        ));
                    }
                }
            )
            ->on(Form::ON_SUBMIT, function (SearchEditor $form) use ($ruleId, $hook) {
                $filter = $form->getFilter();

                $this->session->set(
                    'object_filter',
                    (new RuleSerializer($filter, $hook->getJsonPaths(...$this->collectColumns($filter))))->getJson()
                );
                $this->redirectNow(Links::eventRule($ruleId)->setParam('_filterOnly'));
            });

        $editor->getParser()->on(QueryString::ON_CONDITION, $hook->enrichCondition(...));
        $editor->handleRequest($this->getServerRequest());

        $this->getDocument()->addHtml($editor);

        $this->setTitle($this->translate('Adjust Filter'));
    }

    public function suggestAction(): void
    {
        $hook = $this->resolveSourceHook((int) $this->params->getRequired('id'));
        $requestData = SearchSuggestions::parseRequest($this->getServerRequest()) ?? [];

        $type = $requestData['term']['type'] ?? null;
        $label = $requestData['term']['label'] ?? '';

        if ($type === 'column') {
            $provider = $hook->getColumnSuggestions($label);
        } else {
            $column = $requestData['column'] ?? null;
            if ($column === null || $column === SearchEditor::FAKE_COLUMN) {
                $provider = new EmptyIterator();
            } else {
                /** @var Filter\Chain $searchFilter */
                $searchFilter = QueryString::parse($requestData['searchFilter'] ?? '');
                $provider = $hook->getValueSuggestions($column, $label, $searchFilter);
            }
        }

        $suggestions = (new SearchSuggestions($provider))
            ->setSearchTerm($label)
            ->setOriginalSearchValue($requestData['term']['search'] ?? '');

        if ($type === 'column') {
            $suggestions->setGroupingCallback(fn ($x) => $x['group']);
        }

        $this->getDocument()->addHtml($suggestions);
    }

    protected function resolveSourceHook(int $ruleId): SourceHook
    {
        $source = null;
        if ($ruleId !== -1) {
            $source = Rule::on(Database::get())
                ->columns(['id' => 'source.id', 'type' => 'source.type'])
                ->filter(Filter::all(
                    Filter::equal('id', $ruleId),
                    Filter::equal('deleted', 'n')
                ))
                ->first();
        } elseif (isset($this->session->source)) {
            /** @var ?Source $source */
            $source = Source::on(Database::get())
                ->columns(['id', 'type'])
                ->filter(Filter::equal('id', $this->session->source))
                ->first();
        }

        if ($source === null) {
            $this->httpNotFound($this->translate('Rule not found'));
        }

        $hook = SourceHookLocator::forType($source->type);

        if ($hook === null) {
            $this->httpNotFound(sprintf($this->translate(
                'No source integration available. Either the module supporting sources of type "%s" is not'
                . ' enabled or you have insufficient privileges. Please contact your system administrator.'
            ), $source->type));
        }

        return $hook;
    }

    public function editAction(): void
    {
        $ruleId = (int) $this->params->getRequired('id');

        $eventRuleForm = (new EventRuleForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAvailableSources(
                Database::get()->fetchPairs(
                    Source::on(Database::get())->columns(['id', 'name'])->assembleSelect()
                )
            )
            ->populate([
                'name' => $this->session->get('name'),
                'source' => $this->session->get('source')
            ])
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function ($form) use ($ruleId) {
                $this->session->set('name', $form->getValue('name'));

                $newSource = (int) $form->getValue('source');
                if ($newSource !== $this->session->get('source')) {
                    $this->session->set('source', $newSource);
                    $this->session->set('object_filter', '');
                }

                $this->redirectNow(Links::eventRule($ruleId)->setParam('_nameOnly'));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Edit Event Rule'));

        $this->addContent($eventRuleForm);
    }

    /**
     * Fetch the rule with the given ID
     *
     * @param int $ruleId
     *
     * @return Rule
     *
     * @throws HttpNotFoundException
     */
    private function fetchRule(int $ruleId): Rule
    {
        $query = Rule::on(Database::get())
            ->filter(Filter::equal('id', $ruleId));

        /* @var ?Rule $rule */
        $rule = $query->first();
        if ($rule === null) {
            $this->httpNotFound(t('Rule not found'));
        }

        return $rule;
    }

    /**
     * Get every distinct column used by the filter
     *
     * @param Filter\Rule $rule
     *
     * @return array<string>
     */
    private function collectColumns(Filter\Rule $rule): array
    {
        $columns = [];
        $this->walkFilterTree($rule, $columns);

        return array_keys($columns);
    }

    private function walkFilterTree(Filter\Rule $rule, array &$columns): void
    {
        if ($rule instanceof Filter\Chain) {
            foreach ($rule as $element) {
                $this->walkFilterTree($element, $columns);
            }
        } else {
            /** @var Condition $rule */
            $columns[$rule->getColumn()] = true;
        }
    }
}
