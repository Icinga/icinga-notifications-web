<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Web\Control\SearchEditor;

use EmptyIterator;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\FormElement\SearchSuggestions;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Suggestions for the SearchEditor used to modify rule-filters
 */
class RuleFilterSuggestions extends SearchSuggestions
{
    protected SourceHook $hook;

    /** @var ?string The type of the field for which to show suggestions */
    protected ?string $type = null;

    /**
     * Create new RuleFilterSuggestions
     *
     * {@see forRequest} chooses the correct provider from the given hook and must be called before assemble
     *
     * @param SourceHook $hook
     */
    public function __construct(SourceHook $hook)
    {
        $this->hook = $hook;

        parent::__construct(new EmptyIterator());
    }

    public function forRequest(ServerRequestInterface $request): static
    {
        if ($request->getMethod() !== 'POST') {
            return $this;
        }

        $requestData = json_decode($request->getBody()->read(8192), true);
        if (empty($requestData)) {
            return $this;
        }

        $this->setSearchTerm($requestData['term']['label']);
        $this->setOriginalSearchValue($requestData['term']['search']);
        $this->setExcludeTerms($requestData['exclude'] ?? []);

        $this->type = $requestData['term']['type'] ?? null;

        $column = $requestData['column'] ?? null;
        if ($column === SearchEditor::FAKE_COLUMN) {
            $column = null;
        }

        $searchFilter = QueryString::parse($requestData['searchFilter'] ?? '');
        $searchFilter = $searchFilter instanceof Filter\Chain
            ? $searchFilter
            : Filter::all($searchFilter);

        if ($this->type === 'column') {
            $this->provider = $this->hook->getColumnSuggestions($this->getSearchTerm() ?? '');
            $this->setGroupingCallback(fn ($x) => $x['group']);
        } elseif ($column !== null) {
            $this->provider = $this->hook->getValueSuggestions(
                $column,
                $this->getSearchTerm() ?? '',
                $searchFilter
            );
        }

        return $this;
    }
}
