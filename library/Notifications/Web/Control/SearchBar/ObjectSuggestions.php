<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Web\Control\SearchBar;

use Generator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Data\QueryColumnsProvider;
use Icinga\Module\Notifications\Data\QueryValuesProvider;
use ipl\Html\HtmlElement;
use ipl\Orm\Model;
use ipl\Stdlib\BaseFilter;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar\Suggestions;
use LogicException;

class ObjectSuggestions extends Suggestions
{
    use BaseFilter;

    protected ?Model $model = null;

    /**
     * Set the model to show suggestions for
     *
     * @param string|Model $model
     *
     * @return $this
     */
    public function setModel(string|Model $model): static
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $this->model = $model;

        return $this;
    }

    /**
     * Get the model to show suggestions for
     *
     * @return Model
     */
    public function getModel(): Model
    {
        if ($this->model === null) {
            throw new LogicException(
                'You are accessing an unset property. Please make sure to set it beforehand.'
            );
        }

        return $this->model;
    }

    protected function shouldShowRelationFor(string $column): bool
    {
        return QueryColumnsProvider::shouldShowRelationFor($column, $this->getModel());
    }

    protected function createQuickSearchFilter($searchTerm): Filter\Any|Filter\Chain
    {
        $model = $this->getModel();
        $resolver = $model::on(Database::get())->getResolver();

        $quickFilter = Filter::any();
        foreach ($model->getSearchColumns() as $column) {
            $where = Filter::like($resolver->qualifyColumn($column, $model->getTableName()), $searchTerm);
            $where->metaData()->set('columnLabel', $resolver->getColumnDefinition($where->getColumn())->getLabel());
            $quickFilter->add($where);
        }

        return $quickFilter;
    }

    protected function fetchValueSuggestions($column, $searchTerm, Filter\Chain $searchFilter): Generator
    {
        $query = $this->getModel()::on(Database::get());
        $query->limit(static::DEFAULT_LIMIT);

        $provider = new QueryValuesProvider($query, $column, $searchTerm, $searchFilter);
        if ($this->hasBaseFilter()) {
            $provider->setBaseFilter($this->getBaseFilter());
        }

        foreach ($provider as $result) {
            $search = $result['search'];
            unset($result['search']);

            yield $search => $result;
        }
    }

    protected function fetchColumnSuggestions($searchTerm): Generator
    {
        $provider = new QueryColumnsProvider($this->getModel()::on(Database::get()), $searchTerm);

        $currentGroup = null;
        foreach ($provider as $item) {
            if ($item['group'] !== $currentGroup) {
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

    protected function filterColumnSuggestions($data, $searchTerm): Generator
    {
        yield from $data;
    }
}
