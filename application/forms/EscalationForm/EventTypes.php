<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms\EscalationForm;

use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\FormElement\TermInput;

class EventTypes extends FieldsetElement
{
    use ConfigProvider {
        registerAttributeCallbacks as registerProviderCallbacks;
    }

    protected $defaultAttributes = [
        'class' => ['icinga-controls', 'event-types'],
    ];

    private ?int $ruleId = null;

    protected function registerAttributeCallbacks(Attributes $attributes): void
    {
        $this->registerProviderCallbacks($attributes);

        $attributes->registerAttributeCallback(
            'rule_id',
            null,
            fn (int $id) => $this->ruleId = $id
        );
    }

    /**
     * Prepare the condition for display
     *
     * @param string $query The query string
     *
     * @return string
     */
    public static function prepare(string $query): array
    {
        return ['types' => $query];
    }

    /**
     * Get the condition to store
     *
     * @return string
     */
    public function getCondition(): string
    {
        return $this->getElement('types')->getValue();
    }

    protected function assemble()
    {
        $termInput = (new TermInput('types'))
            ->setRequired($this->isRequired())
            ->setReadOnly();

        $eventTypes = $this->provider->findNotificationEventTypesByRuleId($this->ruleId);
        if (! empty($eventTypes)) {;
            $termInput->setSuggestions(new SearchSuggestions(array_map(
                fn(string $type, string $label) => ['search' => $type, 'label' => $label],
                array_keys($eventTypes),
                array_values($eventTypes)
            )));

            $termValidator = function (array $terms) use ($eventTypes) {
                foreach ($terms as $term) {
                    /** @var TermInput\RegisteredTerm $term */
                    if (! isset($eventTypes[$term->getSearchValue()])) {
                        $term->setMessage(sprintf(
                            $this->translate('Invalid event type: %s'),
                            $term->getSearchValue()
                        ));
                    } else {
                        $term->setLabel($eventTypes[$term->getSearchValue()]);
                    }
                }
            };

            $termInput
                ->on(TermInput::ON_ENRICH, $termValidator)
                ->on(TermInput::ON_ADD, $termValidator)
                ->on(TermInput::ON_PASTE, $termValidator)
                ->on(TermInput::ON_SAVE, $termValidator);
        }

        $this->addElement($termInput);
    }
}
