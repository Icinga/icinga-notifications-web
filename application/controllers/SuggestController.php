<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use DateTime;
use DateTimeZone;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use IntlTimeZone;
use ipl\Stdlib\Filter;
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

    public function recipientAction(): void
    {
        $suggestions = new SearchSuggestions(
            (function () use (&$suggestions) {
                $createExcludeFilter = function (string $for, array $from): Filter\None {
                    $toExclude = array_filter(array_map(function ($term) use ($for) {
                        if (str_contains($term, ':') === false) {
                            return '';
                        }

                        [$type, $id] = explode(':', $term, 2);

                        return $type === $for ? $id : '';
                    }, $from));

                    $filter = Filter::none();
                    if (! empty($toExclude)) {
                        $filter->add(Filter::equal('id', $toExclude));
                    }

                    return $filter;
                };

                $contactFilter = Filter::all(
                    $createExcludeFilter('contact', $suggestions->getExcludeTerms()),
                    Filter::like('full_name', $suggestions->getSearchTerm())
                );
                foreach (Contact::on(Database::get())->filter($contactFilter) as $contact) {
                    yield [
                        'search' => 'contact:' . $contact->id,
                        'label' => $contact->full_name
                    ];
                }

                $groupFilter = Filter::all(
                    $createExcludeFilter('group', $suggestions->getExcludeTerms()),
                    Filter::like('name', $suggestions->getSearchTerm())
                );
                foreach (Contactgroup::on(Database::get())->filter($groupFilter) as $group) {
                    yield [
                        'search' => 'group:' . $group->id,
                        'label' => $group->name
                    ];
                }
            })()
        );

        $suggestions->setGroupingCallback(function (array $data) {
            return str_starts_with($data['search'], 'contact:')
                ? $this->translate('Contacts')
                : $this->translate('Contact Groups');
        });

        $suggestions->forRequest($this->getServerRequest());
        $this->getDocument()->addHtml($suggestions);
    }
}
