<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use Generator;
use Icinga\Module\Notifications\Widget\ItemList\PageSeparatorItem;
use Icinga\Module\Notifications\Widget\ShowMore;
use ipl\Orm\ResultSet;
use ipl\Web\Url;

trait LoadMore
{
    /** @var ?int */
    protected ?int $pageSize = null;

    /** @var ?int */
    protected ?int $pageNumber = null;

    /** @var ?Url */
    protected ?Url $loadMoreUrl = null;

    /**
     * Set the page size
     *
     * @param int $size
     *
     * @return $this
     */
    public function setPageSize(int $size): static
    {
        $this->pageSize = $size;

        return $this;
    }

    /**
     * Set the page number
     *
     * @param int $number
     *
     * @return $this
     */
    public function setPageNumber(int $number): static
    {
        $this->pageNumber = $number;

        return $this;
    }

    /**
     * Set the url to fetch more items
     *
     * @param Url $url
     *
     * @return $this
     */
    public function setLoadMoreUrl(Url $url): static
    {
        $this->loadMoreUrl = $url;

        return $this;
    }

    /**
     * Iterate over the given data
     *
     * Add the page separator and the "LoadMore" button at the desired position
     *
     * @param ResultSet $result
     *
     * @return Generator
     */
    protected function getIterator(ResultSet $result): Generator
    {
        $count = 0;
        $pageNumber = $this->pageNumber ?: 1;

        if ($pageNumber > 1) {
            $this->add(new PageSeparatorItem($pageNumber));
        }

        foreach ($result as $data) {
            $count++;
            if ($count % $this->pageSize === 0) {
                $pageNumber++;
            } elseif ($count > $this->pageSize && $count % $this->pageSize === 1) {
                $this->add(new PageSeparatorItem($pageNumber));
            }

            yield $data;
        }

        if ($count > 0 && $this->loadMoreUrl !== null) {
            $showMore = (new ShowMore(
                $result,
                $this->loadMoreUrl->setParam('page', $pageNumber)
                    ->setAnchor('page-' . ($pageNumber))
            ))
                ->setLabel(t('Load More'))
                ->setAttribute('data-no-icinga-ajax', true);

            $this->add($showMore->setTag('li')->addAttributes(['class' => 'list-item']));
        }
    }
}
