<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter\Condition;
use ipl\Stdlib\Filter\Equal;
use ipl\Stdlib\Filter\GreaterThan;
use ipl\Stdlib\Filter\GreaterThanOrEqual;
use ipl\Stdlib\Filter\LessThan;
use ipl\Stdlib\Filter\LessThanOrEqual;
use ipl\Stdlib\Filter\Unequal;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-type ConditionData array{
 *     column: string,
 *     operator: string,
 *     severity?: string,
 *     no_of?: string,
 *     unit?: string
 * }
 */
class EscalationCondition extends FieldsetElement
{
    protected $defaultAttributes = ['class' => 'escalation-condition'];

    /** @var ?SubmitButtonElement The button to remove this condition */
    protected ?SubmitButtonElement $removeButton = null;

    /**
     * Set the button to remove this condition
     *
     * @param SubmitButtonElement $removeButton
     *
     * @return void
     */
    public function setRemoveButton(SubmitButtonElement $removeButton): void
    {
        $this->removeButton = $removeButton;
    }

    /**
     * Prepare the condition for display
     *
     * @param Condition $condition
     *
     * @return ConditionData
     */
    public static function prepare(Condition $condition): array
    {
        $data = [
            'column' => $condition->getColumn(),
            'operator' => QueryString::getRuleSymbol($condition)
        ];
        if ($data['column'] === 'incident_severity') {
            $data['severity'] = $condition->getValue();
        } else {
            preg_match('/^(\d+)([hms])$/', $condition->getValue(), $matches);
            $data['no_of'] = $matches[1];
            $data['unit'] = $matches[2];
        }

        return $data;
    }

    /**
     * Get the condition to store
     *
     * @return Condition
     */
    public function getCondition(): Condition
    {
        $this->ensureAssembled();

        $column = $this->getElement('column')->getValue();

        $value = match ($column) {
            'incident_severity' => $this->getElement('severity')->getValue(),
            'incident_age' => $this->getElement('no_of')->getValue()
                . $this->getElement('unit')->getValue()
        };

        return match ($this->getElement('operator')->getValue()) {
            '='  => new Equal($column, $value),
            '>'  => new GreaterThan($column, $value),
            '>=' => new GreaterThanOrEqual($column, $value),
            '<'  => new LessThan($column, $value),
            '<=' => new LessThanOrEqual($column, $value),
            '!=' => new Unequal($column, $value)
        };
    }

    protected function assemble(): void
    {
        $this->addElement('select', 'column', [
            'required' => true,
            'options' => [
                '' => sprintf(' - %s - ', $this->translate('Please choose')),
                'incident_severity' => $this->translate('Incident Severity'),
                'incident_age' => $this->translate('Incident Age')
            ],
            'class' => 'autosubmit',
            'disabledOptions' => [''],
            'value' => ''
        ]);
        $this->addHtml(new Icon('spinner', [
            'class' => 'spinner',
            'title' => $this->translate(
                'This page will be automatically updated upon change of the value'
            )
        ]));

        $this->addElement('select', 'operator', [
            'required' => true,
            'options' => [
                '='  => '=',
                '>'  => '>',
                '>=' => '>=',
                '<'  => '<',
                '<=' => '<=',
                '!=' => '!='
            ]
        ]);

        if ($this->getPopulatedValue('column') === 'incident_severity') {
            $this->addElement('select', 'severity', [
                'required' => true,
                'options' => [
                    'ok' => $this->translate('Ok', 'notification.severity'),
                    'debug' => $this->translate('Debug', 'notification.severity'),
                    'info' => $this->translate('Information', 'notification.severity'),
                    'notice' => $this->translate('Notice', 'notification.severity'),
                    'warning' => $this->translate('Warning', 'notification.severity'),
                    'err' => $this->translate('Error', 'notification.severity'),
                    'crit' => $this->translate('Critical', 'notification.severity'),
                    'alert' => $this->translate('Alert', 'notification.severity'),
                    'emerg' => $this->translate('Emergency', 'notification.severity')
                ]
            ]);
        } elseif ($this->getPopulatedValue('column') === 'incident_age') {
            $noOf = $this->createElement('number', 'no_of', [
                'required' => true,
                'min' => 1,
                'step' => 1,
                'value' => 1
            ]);
            $unit = $this->createElement('select', 'unit', [
                'required' => true,
                'options' => [
                    'h' => $this->translate('Hours'),
                    'm' => $this->translate('Minutes'),
                    's' => $this->translate('Seconds')
                ]
            ]);

            $this->registerElement($noOf);
            $this->registerElement($unit);

            $this->addHtml(new HtmlElement('div', Attributes::create(['class' => 'age-inputs']), $noOf, $unit));
        } else {
            $this->addElement('text', 'noop', [
                'required' => true,
                'placeholder' => $this->translate('Please make a decision'),
                'disabled' => true
            ]);
        }

        if ($this->removeButton !== null) {
            $this->addHtml(
                $this->removeButton->setLabel(new Icon('minus'))
                    ->setAttribute('class', ['remove-button', 'animated'])
                    ->setAttribute('title', $this->translate('Remove Condition'))
            );
        } else {
            $this->addHtml(new HtmlElement('span', Attributes::create([
                'class' => 'remove-button-disabled',
                'title' => $this->translate('Only the first escalation can be immediately triggered')
            ]), (new Icon('minus'))));
        }
    }
}
