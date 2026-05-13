<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Web\Control\SearchEditor;

use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Html\HtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar\Suggestions;
use Traversable;

/**
 * Suggestions for the SearchEditor used to modify rule-filters
 */
class RuleFilterSuggestions extends Suggestions
{
    protected SourceHook $hook;

    public function __construct(SourceHook $hook)
    {
        $this->hook = $hook;
    }

    /**
     * These suggestions aren't used by any searchbar, so this function is never called
     */
    protected function createQuickSearchFilter($searchTerm)
    {
    }

    protected function fetchValueSuggestions($column, $searchTerm, Filter\Chain $searchFilter): Traversable
    {
        yield from $this->hook->getValueSuggestions($column, $searchTerm, $searchFilter);
    }

    protected function shouldShowRelationFor(string $column): bool
    {
        return $this->hook->shouldShowRelationFor($column);
    }

    protected function fetchColumnSuggestions($searchTerm): Traversable
    {
        $currentGroup = null;
        foreach ($this->hook->getColumnSuggestions($searchTerm) as $item) {
            if (isset($item['group']) && $item['group'] !== $currentGroup) {
                $currentGroup = $item['group'];
                $this->addHtml(HtmlElement::create(
                    'li',
                    ['class' => static::SUGGESTION_TITLE_CLASS],
                    $currentGroup
                ));
            }

            yield $item['search'] => $item['label'];
        }
    }
}
