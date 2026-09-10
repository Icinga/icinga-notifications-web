<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Application\Logger;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Module\Notifications\Common\SourceHookLocator;
use Icinga\Module\Notifications\Form\Data\EscalationRule;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Util\RuleSerializer;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Seq;
use ipl\Web\Common\CalloutType;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Callout;
use JsonException;
use Throwable;

class RuleFilterForm extends SearchEditor
{
    public function __construct()
    {
        $this->getAttribute('class')
            ->addValue('event-rule-filter');
    }

    /**
     * Set the rule to populate the form with
     *
     * @param Rule $rule
     *
     * @return $this
     *
     * @throws ConfigurationError
     */
    public function setRule(Rule $rule): static
    {
        $values = [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'source_type' => $rule->source_type
        ];

        if ($rule->object_filter) {
            try {
                $parsedFilter = json_decode($rule->object_filter, true, flags: JSON_THROW_ON_ERROR);
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
                    $rule->id,
                    $version,
                    RuleSerializer::VERSION
                );
                throw new ConfigurationError($this->translate(
                    'Unsupported rule filter version. Please contact your system administrator.'
                ));
            }

            if (($parsedFilter['assisted'] ?? false) && $this->getHook($rule->source_type) === null) {
                throw new ConfigurationError($this->translate(
                    'No source integration available. Either the module supporting sources of type "%s" is not'
                    . ' enabled or you have insufficient privileges. Please contact your system administrator.'
                ));
            }

            $this->setQueryString($parsedFilter['qs'] ?? '');
            $values['filter_name'] = $parsedFilter['filter_name'] ?? null;
        }

        $this->populate($values);

        return $this;
    }

    /**
     * Get the rule as it's currently configured
     *
     * @return EscalationRule
     *
     * @throws JsonEncodeException
     */
    public function getRule(): EscalationRule
    {
        $filter = $this->getFilter();

        $hook = $this->getHook($this->getValue('source_type'));
        if ($hook !== null) {
            $jsonPaths = $hook->getJsonPaths(
                ...Seq::unique(
                    Seq::map($filter->yieldRules(), fn($r) => $r->getColumn())
                )
            );
        } else {
            $jsonPaths = [];
            foreach (Seq::unique(Seq::map($filter->yieldRules(), fn($r) => $r->getColumn())) as $path) {
                $jsonPaths[$path] = [$path];
            }
        }

        return new EscalationRule(
            $this->getValue('rule_id'),
            $this->getValue('rule_name'),
            $this->getValue('source_type'),
            (new RuleSerializer(
                $filter,
                $jsonPaths,
                $hook !== null,
                $this->getValue('filter_name')
            ))->getJson()
        );
    }

    protected function assemble(): void
    {
        $this->addElement('hidden', 'rule_id', ['required' => true]);
        $this->addElement('hidden', 'rule_name', ['required' => true]);
        $this->addElement('hidden', 'source_type', ['required' => true]);

        $hook = $this->getHook($this->getValue('source_type'));
        if ($hook !== null) {
            $this->on(
                SearchEditor::ON_VALIDATE_COLUMN,
                function (Filter\Condition $condition) use ($hook) {
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
            );

            $this->getParser()->on(
                QueryString::ON_CONDITION,
                function (Filter\Condition $condition) use ($hook) {
                    try {
                        $hook->enrichCondition($condition);
                    } catch (Throwable $e) {
                        Logger::error(
                            'Source hook %s failed to enrich filter condition: %s',
                            get_class($hook),
                            $e
                        );
                    }
                }
            );
        } else {
            $this->addHtml(
                new Callout(
                    CalloutType::Info,
                    Text::create(
                        $this->translate(
                            'Please make sure columns are valid JSON paths, '
                            . 'as no validation is available for this source. '
                            . 'Refer to the source\'s documentation for available columns.'
                        )
                    )
                )
            );
        }

        $this->addElement('text', 'filter_name', [
            'label' => $this->translate('Filter Name'),
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

        parent::assemble();

        $this->getElement('btn_submit')->setLabel($this->translate('Save Changes'));
    }

    /**
     * Get source integration of the given type
     *
     * @param string $type
     *
     * @return ?SourceHook
     */
    private function getHook(string $type): ?SourceHook
    {
        return SourceHookLocator::forType($type);
    }
}
