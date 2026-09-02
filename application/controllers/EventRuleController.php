<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\MissingParameterException;
use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Data\NotificationConfigProvider;
use Icinga\Module\Notifications\Forms\EventRuleConfigForm;
use Icinga\Module\Notifications\Forms\EventRuleForm;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Repository\EscalationRuleRepository;
use Icinga\Module\Notifications\Util\RuleSerializer;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Html;
use ipl\Html\Text;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;
use ipl\Stdlib\Seq;
use ipl\Web\Common\CalloutType;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\Url;
use ipl\Web\Widget\Callout;
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

    /**
     * searchEditorAction for editing filters
     *
     * @return void
     *
     * @throws MissingParameterException
     */
    public function searchEditorAction(): void
    {
        $ruleId = (int) $this->params->getRequired('id');
        $filter = $this->params->get('object_filter', $this->session->get('object_filter'));

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
                    'Cannot load filter for rule with id %d: filter version \'%s\' is not supported (expected %d)',
                    $ruleId,
                    $version,
                    RuleSerializer::VERSION
                );
                throw new ConfigurationError($this->translate(
                    'Unsupported rule filter version. Please contact your system administrator.'
                ));
            }
        }

        $hook = $this->resolveSourceHook($ruleId, (bool) ($parsedFilter['assisted'] ?? false));

        $editor = (new SearchEditor())
            ->addAttributes(Attributes::create(['class' => 'event-rule-filter']))
            ->setQueryString($parsedFilter['qs'] ?? '')
            ->setAction(Url::fromRequest()->with('object_filter', $filter)->getAbsoluteUrl());

        $filterNameElement = $editor->createElement('text', 'filter_name', [
            'label' => $this->translate('Filter Name'),
            'value' => $parsedFilter['filter_name'] ?? null,
            'decorators' => [
                'Label',
                'LabelGroup' => [
                    'name' => 'HtmlTag',
                    'options' => [
                        'tag' => 'div',
                        'class' => 'control-label-group'
                    ]
                ],
                'RenderElement',
                'ControlGroup' => [
                    'name' => 'HtmlTag',
                    'options' => [
                        'tag' => 'div',
                        'class' => 'control-group filter-name'
                    ]
                ],
            ]
        ]);

        $filterNameElement->applyDecoration();
        $editor->registerElement($filterNameElement);
        $editor->prependHtml($filterNameElement);

        if ($hook !== null) {
            $editor->setSuggestionUrl(
                Url::fromPath(
                    'notifications/event-rule/suggest',
                    ['id' => $ruleId, '_disableLayout' => true, 'showCompact' => true]
                )
            )->on(
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
            )->getParser()->on(QueryString::ON_CONDITION, function (Condition $condition) use ($hook) {
                try {
                    $hook->enrichCondition($condition);
                } catch (Throwable $e) {
                    Logger::error(
                        'Source hook %s failed to enrich filter condition: %s',
                        get_class($hook),
                        $e
                    );
                }
            });
            $getJsonPaths = function (Filter\Chain $filter) use ($hook) {
                return $hook->getJsonPaths(
                    ...Seq::unique(
                        Seq::map($filter->yieldRules(), fn($r) => $r->getColumn())
                    )
                );
            };
        } else {
            $getJsonPaths = function (Filter\Chain $filter) {
                $jsonPaths = [];
                foreach (Seq::unique(Seq::map($filter->yieldRules(), fn($r) => $r->getColumn())) as $path) {
                    $jsonPaths[$path] = [$path];
                }

                return $jsonPaths;
            };
        }

        $assisted = $hook !== null;
        $editor->on(Form::ON_SUBMIT, function (SearchEditor $form) use ($ruleId, $getJsonPaths, $assisted) {
            $filter = $form->getFilter();
            $this->session->set(
                'object_filter',
                (new RuleSerializer(
                    $filter,
                    $getJsonPaths($filter),
                    $assisted,
                    $form->getValue('filter_name')
                ))->getJson()
            );
            $this->redirectNow(Links::eventRule($ruleId)->setParam('_filterOnly'));
        })->handleRequest($this->getServerRequest());

        if ($hook === null) {
            $this->getDocument()->addHtml(
                (new Callout(
                    CalloutType::Info,
                    Text::create(
                        $this->translate(
                            'Please make sure columns are valid JSON paths, '
                            . 'as no validation is available for this source. '
                            . 'Refer to the source\'s documentation for available columns.'
                        )
                    )
                ))
                    ->addAttributes(Attributes::create(['class' => 'generic-source-hint']))
            );
        }

        $this->getDocument()->addHtml($editor);

        $this->setTitle($this->translate('Adjust Filter'));
    }

    public function suggestAction(): void
    {
        $hook = $this->resolveSourceHook((int) $this->params->getRequired('id'), true);
        $requestData = SearchSuggestions::parseRequest($this->getServerRequest()) ?? [];

        $type = $requestData['term']['type'] ?? null;
        $label = $requestData['term']['label'] ?? '';
        $failureMessage = null;

        if ($type === 'column') {
            $provider = $hook->getColumnSuggestions($label);
        } else {
            $column = $requestData['column'] ?? null;
            if ($column === null || $column === SearchEditor::FAKE_COLUMN) {
                $failureMessage = $this->translate('Missing column name');
                $provider = [];
            } else {
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

    protected function resolveSourceHook(int $ruleId, bool $required): ?SourceHook
    {
        $type = $this->session->get('source_type');
        if ($type === null && $ruleId !== -1) {
            $type = Rule::on(Database::get())
                ->columns(['source_type'])
                ->filter(Filter::equal('id', $ruleId))
                ->first()?->source_type;
        }

        if ($type === null) {
            $this->httpNotFound($this->translate('Rule not found'));
        }

        $hook = SourceHookLocator::forType($type);

        if (! $required) {
            return $hook;
        }

        if ($hook === null) {
            $this->httpNotFound(sprintf($this->translate(
                'No source integration available. Either the module supporting sources of type "%s" is not'
                . ' enabled or you have insufficient privileges. Please contact your system administrator.'
            ), $type));
        }

        return $hook;
    }

    public function editAction(): void
    {
        $ruleId = (int) $this->params->getRequired('id');

        $eventRuleForm = (new EventRuleForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAvailableSourceTypes(
                Database::get()->fetchCol(
                    Source::on(Database::get())->columns(['type'])->assembleSelect()->distinct()
                )
            )
            ->populate([
                'name' => $this->session->get('name'),
                'source_type' => $this->session->get('source_type')
            ])
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function ($form) use ($ruleId) {
                $this->session->set('name', $form->getValue('name'));

                $newSource = $form->getValue('source_type');
                if ($newSource !== $this->session->get('source_type')) {
                    $this->session->set('source_type', $newSource);
                    $this->session->set('object_filter', '');
                }

                $this->redirectNow(Links::eventRule($ruleId)->setParam('_nameOnly'));
            })->handleRequest($this->getServerRequest());

        $this->setTitle($this->translate('Edit Event Rule'));

        $this->addContent($eventRuleForm);
    }
}
