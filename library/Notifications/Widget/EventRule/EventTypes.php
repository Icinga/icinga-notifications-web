<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\EventRule;

use Icinga\Module\Notifications\Common\SourceHookLocator;
use ipl\Html\HtmlDocument;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\CalloutType;
use ipl\Web\Widget\Callout;

/**
 * HTML representation for notification event types
 */
class EventTypes extends HtmlDocument
{
    use Translation;

    public function __construct(
        private readonly string $sourceType,
        private readonly array $types
    ) {
    }

    public static function fromCommaSeparatedString(string $sourceType, string $types): static
    {
        return new static($sourceType, array_filter(array_map('trim', explode(',', $types))));
    }

    protected function assemble()
    {
        $hook = SourceHookLocator::forType($this->sourceType);

        $typeLabels = [];
        $invalidTypes = [];
        if ($hook !== null) {
            $eventTypes = $hook->getEventTypes();
            foreach ($this->types as $eventType) {
                if (isset($eventTypes[$eventType])) {
                    $typeLabels[] = $eventTypes[$eventType];
                } else {
                    $invalidTypes[] = $eventType;
                }
            }
        } else {
            $typeLabels = $this->types;
        }

        if (! empty($invalidTypes)) {
            $this->addHtml(new Callout(
                CalloutType::Warning,
                empty($typeLabels)
                    ? $this->translate('Impossible match: None of the configured event types are valid.')
                    : sprintf(
                        $this->translate(
                            'Invalid event types found. Only %d of the configured %d event types are valid.'
                        ),
                        count($typeLabels),
                        count($this->types)
                    )
            ));
        } else {
            $this->addHtml(Text::create(sprintf(
                $this->translatePlural(
                    '%1$s',
                    '%2$s and %1$s',
                    count($typeLabels)
                ),
                array_pop($typeLabels),
                implode(', ', $typeLabels)
            )));
        }
    }
}
