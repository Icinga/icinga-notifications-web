<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use ipl\Html\Attributes;
use ipl\Html\Contract\FormElement;
use ipl\Html\FormElement\SubmitButtonElement;

trait DynamicElements
{
    /**
     * Create a button to add a new element
     *
     * @return SubmitButtonElement
     */
    abstract protected function createAddButton(): SubmitButtonElement;

    /**
     * Create a new element
     *
     * The given remove button must be rendered in the element's content.
     *
     * @param int $no The position of the element in the list
     * @param ?SubmitButtonElement $removeButton The button to remove the element, if any
     *
     * @return FormElement
     */
    abstract protected function createDynamicElement(int $no, ?SubmitButtonElement $removeButton): FormElement;

    /**
     * Create a button to remove the given element
     *
     * Note that the button will only be registered in this set but not added to its content.
     * {@see DynamicElements::createDynamicElement()} is responsible for that.
     *
     * @param int $no The position of the element in the list
     *
     * @return SubmitButtonElement
     */
    protected function createRemoveButton(int $no): SubmitButtonElement
    {
        /** @var SubmitButtonElement $remove */
        $remove = $this->createElement('submitButton', sprintf('remove_%d', $no), [
            'formnovalidate' => true
        ]);

        $this->registerElement($remove);

        return $remove;
    }

    public function populate($values): static
    {
        if (! isset($values['count'])) {
            // Ensure a count is set upon the initial population
            $values['count'] = count($values);
        }

        return parent::populate($values);
    }

    protected function assemble(): void
    {
        $expectedCount = (int) $this->getPopulatedValue('count', $this->isRequired() ? 1 : 0);

        $count = 0; // Increases until $expectedCount is reached, ensuring proper association with form data
        $newCount = 0; // The actual number of restored elements, minus the one that has been removed
        while ($count < $expectedCount) {
            $remove = $this->createRemoveButton($count);
            if ($remove->hasBeenPressed()) {
                $this->clearPopulatedValue($remove->getName());
                $this->clearPopulatedValue($count);

                // Re-index populated values to ensure proper association with form data
                foreach (range($count + 1, $expectedCount - 1) as $i) {
                    $expectedValue = $this->getPopulatedValue($i);
                    if ($expectedValue !== null) {
                        $this->populate([$i - 1 => $expectedValue]);
                    }
                }
            } else {
                $newCount++;
            }

            $count++;
        }

        $add = $this->createAddButton()->addAttributes(Attributes::create(['formnovalidate' => true]));
        $this->registerElement($add);
        if ($add->hasBeenPressed()) {
            $this->createRemoveButton($newCount);
            $newCount++;
        }

        if ($newCount === 1 && $this->isRequired()) {
            $this->addElement(
                $this->createDynamicElement(0, null)
                    ->addAttributes(['class' => 'dynamic-item'])
            );
        } else {
            for ($i = 0; $i < $newCount; $i++) {
                /** @var SubmitButtonElement $remove */
                $remove = $this->getElement(sprintf('remove_%d', $i));
                $this->addElement(
                    $this->createDynamicElement($i, $remove)
                        ->addAttributes(['class' => 'dynamic-item'])
                );
            }
        }

        $this->addElement($add);

        $this->clearPopulatedValue('count');
        $this->addElement('hidden', 'count', ['ignore' => true, 'value' => $newCount]);

        $this->addAttributes(Attributes::create(['class' => ['dynamic-list', $newCount === 0 ? 'empty' : '']]));
    }
}
