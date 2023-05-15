<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Web\Control\SearchBar;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\ObjectExtraTag;
use Icinga\Module\Noma\Util\ObjectSuggestionsCursor;
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
