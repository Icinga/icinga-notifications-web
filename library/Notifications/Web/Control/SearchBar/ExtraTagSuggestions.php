<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Web\Control\SearchBar;

use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\ObjectExtraTag;
use Icinga\Module\Notifications\Util\ObjectSuggestionsCursor;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar\Suggestions;

class ExtraTagSuggestions extends Suggestions
{
    protected function fetchValueSuggestions($column, $searchTerm, Filter\Chain $searchFilter): Generator
    {
        yield;
    }

    protected function createQuickSearchFilter($searchTerm): Filter\Any
    {
        return Filter::any();
    }

    protected function fetchColumnSuggestions($searchTerm): Generator
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
