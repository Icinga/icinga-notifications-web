<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Web\Control\SearchBar;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Model\ObjectExtraTag;
use Icinga\Module\Noma\Util\ObjectSuggestionsCursor;
use ipl\Web\Control\SearchBar\Suggestions;
use ipl\Stdlib\Filter;

class ExtraTagSuggestions extends Suggestions
{
    protected function fetchValueSuggestions($column, $searchTerm, Filter\Chain $searchFilter)
    {
    }

    protected function createQuickSearchFilter($searchTerm)
    {
        $quickFilter = Filter::any();

        foreach ($this->getSearchColumns() as $column) {
            $where = Filter::like($column->tag, $searchTerm);
            $where->metaData()->set('columnLabel', $column->tag);
            $quickFilter->add($where);
        }

        return $quickFilter;
    }

    protected function getSearchColumns()
    {
        return (new ObjectSuggestionsCursor(
            Database::get(),
            (new ObjectExtraTag())::on(Database::get())
                ->columns(['tag'])
                ->assembleSelect()
                ->distinct()
        ));
    }

    protected function fetchColumnSuggestions($searchTerm)
    {
        // Object Extra Tags
        foreach ($this->getSearchColumns() as $column) {
            yield $column->tag => $column->tag;
        }
    }
}
