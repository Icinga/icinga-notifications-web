<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms\RuleEntryForm;

use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Str;
use ipl\Web\Common\CalloutType;
use ipl\Web\Filter\Parser;
use ipl\Web\Filter\Renderer;
use ipl\Web\FormElement\SearchSuggestions;
use ipl\Web\FormElement\TermInput;
use ipl\Web\Widget\Callout;

class EventTypes extends FieldsetElement
{
    protected $defaultAttributes = [
        'class' => ['icinga-controls', 'event-types'],
    ];

    private ?SourceHook $hook = null;

    protected function registerAttributeCallbacks(Attributes $attributes): void
    {
        parent::registerAttributeCallbacks($attributes);

        $attributes->registerAttributeCallback(
            'hook',
            null,
            fn (?SourceHook $h) => $this->hook = $h
        );
    }

    /**
     * Prepare the condition for display
     *
     * @param string $query The query string
     *
     * @return array
     */
    public static function prepare(string $query): array
    {
        return ['types' => implode(',', array_map(
            fn (Filter\Condition $c) => $c->getValue(),
            iterator_to_array((new Parser($query))->setStrict()->parse())
        ))];
    }

    /**
     * Get the condition to store
     *
     * @return array{0: Filter\Rule, 1: string}
     */
    public function getCondition(): array
    {
        $filters = Filter::any();
        foreach (array_filter(Str::trimSplit($this->getElement('types')->getValue())) as $type) {
            $filters->add(Filter::equal('event_type', $type));
        }

        return [$filters, (new Renderer($filters))->setStrict()->render()];
    }

    protected function assemble()
    {
        if ($this->hook === null) {
            $this->addHtml(new Callout(
                CalloutType::Info,
                $this->translate(
                    'Please make sure types are valid, as no validation is available for this source.'
                    . ' Refer to the source\'s documentation for valid event types.'
                )
            ));
        }

        $termInput = (new TermInput('types'))
            ->setRequired($this->isRequired())
            ->setReadOnly();

        $eventTypes = $this->hook?->getEventTypes() ?? [];
        if (! empty($eventTypes)) {
            $termInput->setSuggestions(new SearchSuggestions(array_map(
                fn(string $type, string $label) => ['search' => $type, 'label' => $label],
                array_keys($eventTypes),
                array_values($eventTypes)
            )));

            $termInput->on(TermInput::ON_ENRICH, function (array $terms) use ($eventTypes) {
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
            });
        }

        $this->addElement($termInput);
    }
}
