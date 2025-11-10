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
        $suggestions = new SearchSuggestions((function () use (&$suggestions) {
            foreach (IntlTimeZone::createEnumeration() as $tz) {
                try {
                    if (
                        (new DateTime('now', new DateTimeZone($tz)))->getTimezone()->getLocation()
                        && $suggestions->matchSearch($tz)
                    ) {
                        yield ['search' => $tz];
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        })());

        $this->getDocument()->addHtml($suggestions->forRequest($this->getServerRequest()));
    }
}
