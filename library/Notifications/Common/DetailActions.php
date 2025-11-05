<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use ipl\Html\BaseHtmlElement;
use ipl\Stdlib\Filter;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

trait DetailActions
{
    protected bool $detailActionsDisabled = false;

    /**
     * Set whether this list should be an action-list
     *
     * @param bool $state
     *
     * @return $this
     */
    public function setDetailActionsDisabled(bool $state = true): static
    {
        $this->detailActionsDisabled = $state;

        return $this;
    }

    /**
     * Get whether this list should be an action-list
     *
     * @return bool
     */
    public function getDetailActionsDisabled(): bool
    {
        return $this->detailActionsDisabled;
    }

    /**
     * Prepare this list as action-list
     *
     * @return $this
     */
    public function initializeDetailActions(): static
    {
        $this->getAttributes()
            ->registerAttributeCallback(
                'class',
                fn () => $this->getDetailActionsDisabled() ? null : 'action-list'
            );

        return $this;
    }

    /**
     * Set the url to use for a single selected list item
     *
     * @param Url $url
     *
     * @return $this
     */
    protected function setDetailUrl(Url $url): static
    {
        $this->getAttributes()
            ->registerAttributeCallback(
                'data-icinga-detail-url',
                fn() => $this->getDetailActionsDisabled() ? null : (string) $url
            );

        return $this;
    }

    /**
     * Associate the given element with the given single-selection filter
     *
     * @param BaseHtmlElement $element
     * @param Filter\Rule     $filter
     *
     * @return $this
     */
    public function addDetailFilterAttribute(BaseHtmlElement $element, Filter\Rule $filter): static
    {
        $element->getAttributes()
            ->registerAttributeCallback(
                'data-action-item',
                fn() => ! $this->getDetailActionsDisabled()
            )
            ->registerAttributeCallback(
                'data-icinga-detail-filter',
                fn() => $this->getDetailActionsDisabled() ? null : QueryString::render($filter)
            );

        return $this;
    }
}
