<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use DateTime;
use DateTimeZone;
use IntlTimeZone;
use ipl\Web\Compat\CompatController;
use ipl\Web\FormElement\SearchSuggestions;
use Throwable;

class SuggestController extends CompatController
{
    public function timezoneAction(): void
    {
        $default = $this->params->get('default');

        $suggestions = new SearchSuggestions((function () use (&$suggestions, $default) {
            // https://github.com/php/php-src/issues/11874#issuecomment-1666223477
            $timezones = IntlTimeZone::createEnumeration() ?: [];

            $matches = [];
            foreach ($timezones as $tz) {
                try {
                    if (
                        (new DateTime('now', new DateTimeZone($tz)))->getTimezone()->getLocation()
                        && $tz !== $default
                        && $suggestions->matchSearch($tz)
                    ) {
                        $matches[] = $tz;
                    }
                } catch (Throwable) {
                    continue;
                }
            }

            if (count($matches) === 1) {
                yield ['search' => $matches[0]];
            } else {
                if ($default) {
                    yield ['search' => $default];
                }

                foreach ($matches as $match) {
                    yield ['search' => $match];
                }
            }
        })());

        $suggestions->setGroupingCallback(function (array $data) use ($default) {
            return match ($data['search']) {
                $default => t('Default'),
                default => t('Matches')
            };
        });

        $this->getDocument()->addHtml($suggestions->forRequest($this->getServerRequest()));
    }
}
