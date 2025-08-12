<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use Icinga\Module\Notifications\Model\RuleEscalation;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Icon;

/**
 * @phpstan-import-type EscalationData from Escalation
 */
class Escalations extends FieldsetElement
{
    use ConfigProvider;
    use DynamicElements;

    protected $defaultAttributes = ['class' => 'escalations'];

    protected function createAddButton(): SubmitButtonElement
    {
        /** @var SubmitButtonElement $button */
        $button = $this->createElement('submitButton', 'add-button', [
            'title' => $this->translate('Add Escalation'),
            'label' => new Icon('plus'),
            'class' => ['add-button', 'animated']
        ]);

        $button->addWrapper(new HtmlElement('div', Attributes::create(['class' => 'add-button-wrapper'])));

        return $button;
    }

    protected function createDynamicElement(int $no, ?SubmitButtonElement $removeButton): FormElement
    {
        $escalation = new Escalation($no, ['provider' => $this->provider, 'immediate' => $no === 0]);
        if ($removeButton !== null) {
            $escalation->setRemoveButton($removeButton);
        }

        return $escalation;
    }

    /**
     * Prepare the escalations for display
     *
     * @param iterable<RuleEscalation> $escalations
     *
     * @return array<EscalationData>
     */
    public static function prepare(iterable $escalations): array
    {
        $values = [];
        foreach ($escalations as $escalation) {
            $values[] = Escalation::prepare($escalation);
        }

        return $values;
    }

    /**
     * Get the escalations to store
     *
     * @return array<Escalation>
     */
    public function getEscalations(): array
    {
        $escalations = [];
        foreach ($this->ensureAssembled()->getElements() as $element) {
            if ($element instanceof Escalation) {
                $escalations[] = $element;
            }
        }

        return $escalations;
    }
}
