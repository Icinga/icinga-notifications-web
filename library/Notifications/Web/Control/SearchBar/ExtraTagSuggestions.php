<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Web\Control\SearchBar;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\ObjectExtraTag;
use Icinga\Module\Notifications\Util\ObjectSuggestionsCursor;
use ipl\Web\Control\SearchBar\Suggestions;
use ipl\Stdlib\Filter;
use Traversable;

class ExtraTagSuggestions extends Suggestions
{
    protected function fetchValueSuggestions($column, $searchTerm, Filter\Chain $searchFilter)
    {
        yield;
    }

    protected function createQuickSearchFilter($searchTerm)
    {
        return Filter::any();
    }

    protected function fetchColumnSuggestions($searchTerm)
    {
        $searchColumns = (new ObjectSuggestionsCursor(
            Database::get(),
            (new ObjectExtraTag())::on(Database::get())
                ->columns(['tag'])
                ->assembleSelect()
                ->distinct()
        ));

        // Object Extra Tags
        foreach ($searchColumns as $column) {
            yield $column->tag => $column->tag;
        }
    }
}
