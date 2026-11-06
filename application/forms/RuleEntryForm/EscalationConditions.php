<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms\RuleEntryForm;

use Icinga\Module\Notifications\Common\Severity;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;
use RuntimeException;

/**
 * @phpstan-import-type ConditionValues from EscalationCondition
 */
class EscalationConditions extends FieldsetElement
{
    use DynamicElements;

    protected $defaultAttributes = ['class' => 'escalation-conditions'];

    /** @var ?int Which element uses the incident_age column */
    private ?int $incidentAgeUser = null;

    /** @var ?int Which element uses the is_managed column */
    private ?int $isManagedUser = null;

    /** @var array<int, Severity[]> Which element may use which severities */
    private array $severityUsers = [];

    /** @var ?Severity[] Which severities are unused */
    private ?array $severityCasesLeft = null;

    protected function createAddButton(): ?SubmitButtonElement
    {
        $this->loadConstraints();
        if (! isset($this->incidentAgeUser)) {
            // pass
        } elseif (! isset($this->isManagedUser)) {
            // pass
        } elseif (empty($this->severityCasesLeft)) {
            return null;
        }

        /** @var SubmitButtonElement $button */
        $button = $this->createElement('submitButton', 'add-button', [
            'title' => $this->translate('Add Condition'),
            'label' => [
                new Icon('plus'),
                new HtmlElement('span', content: Text::create($this->translate('Add Condition')))
            ],
            'class' => ['add-button', 'animated', 'link-button']
        ]);

        $button->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'add-button-wrapper'])));

        return $button;
    }

    protected function createDynamicElement(int $no, ?SubmitButtonElement $removeButton): FormElement
    {
        $condition = new EscalationCondition($no);

        $columns = [];
        if (! isset($this->incidentAgeUser) || $this->incidentAgeUser === $no) {
            $columns[] = 'incident_age';
        }
        if (! isset($this->isManagedUser) || $this->isManagedUser === $no) {
            $columns[] = 'is_managed';
        }
        if (isset($this->severityUsers[$no]) || ! empty($this->severityCasesLeft)) {
            $columns[] = 'incident_severity';
            $condition->setAllowedSeverities($this->severityUsers[$no] ?? $this->severityCasesLeft);
        }

        $condition->setAllowedColumns($columns);

        if ($removeButton !== null) {
            $condition->setRemoveButton($removeButton);
        }

        return $condition;
    }

    /**
     * Prepare the condition for display
     *
     * @param string $query The query string
     *
     * @return array<ConditionValues>
     */
    public static function prepare(string $query): array
    {
        $filters = QueryString::parse($query);
        if ($filters instanceof Condition) {
            $filters = [$filters];
        }

        $conditions = [];
        foreach ($filters as $condition) {
            $conditions[] = EscalationCondition::prepare($condition);
        }

        return $conditions;
    }

    /**
     * Get the condition to store
     *
     * @return array{0: Filter\Rule, 1: string}
     */
    public function getCondition(): array
    {
        $filters = Filter::all();
        foreach ($this->ensureAssembled()->getElements() as $element) {
            if ($element instanceof EscalationCondition) {
                $filters->add($element->getCondition());
            }
        }

        return [$filters, QueryString::render($filters)];
    }

    private function loadConstraints(): void
    {
        $this->severityCasesLeft = Severity::cases();
        foreach ($this->getAllPopulatedValues() as $elementNo => $values) {
            if (! is_int($elementNo)) {
                continue;
            }

            if ($values['column'] === 'incident_age') {
                $this->incidentAgeUser = $elementNo;
            } elseif ($values['column'] === 'is_managed') {
                $this->isManagedUser = $elementNo;
            } elseif ($values['column'] === 'incident_severity') {
                $this->severityUsers[$elementNo] = $this->severityCasesLeft;
                if (isset($values['severity'])) {
                    $usedSeverityPos = array_search(
                        $values['severity'],
                        array_map(fn(Severity $s) => $s->value, $this->severityCasesLeft),
                        true
                    );
                    if ($usedSeverityPos === false) {
                        throw new RuntimeException(sprintf(
                            'Severity %s is not available for element %d',
                            $values['severity'],
                            $elementNo
                        ));
                    }

                    $this->severityCasesLeft = match ($values['operator']) {
                        '=' => [],
                        '!=' => $this->severityCasesLeft,
                        '>=' => array_slice($this->severityCasesLeft, $usedSeverityPos + 1),
                        '>' => array_slice($this->severityCasesLeft, $usedSeverityPos + 2),
                        '<=' => array_slice($this->severityCasesLeft, 0, $usedSeverityPos),
                        '<' => array_slice($this->severityCasesLeft, 0, $usedSeverityPos - 1)
                    };
                }
            }
        }
    }
}
