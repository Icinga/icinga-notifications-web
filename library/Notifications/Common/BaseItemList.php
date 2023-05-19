<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Widget\EmptyState;
use InvalidArgumentException;
use ipl\Html\BaseHtmlElement;
use ipl\Stdlib\BaseFilter;

abstract class BaseItemList extends BaseHtmlElement
{
    use BaseFilter;

    protected $baseAttributes = [
        'class' => 'item-list',
        'data-base-target' => '_next',
        'data-pdfexport-page-breaks-at' => '.list-item'
    ];

    protected $tag = 'ul';

    /** @var iterable */
    protected $data;

    /**
     * Create a new item  list
     *
     * @param iterable $data Data source of the list
     */
    public function __construct($data)
    {
        if (! is_iterable($data)) {
            throw new InvalidArgumentException('Data must be an array or an instance of Traversable');
        }

        $this->data = $data;

        $this->addAttributes($this->baseAttributes);
        $this->getAttributes()
            ->registerAttributeCallback('class', function () {
                return 'action-list';
            });

        $this->init();
    }

    /**
     * Initialize the item list
     *
     * If you want to adjust the item list after construction, override this method.
     */
    protected function init(): void
    {
    }

    abstract protected function getItemClass(): string;

    protected function assemble()
    {
        $itemClass = $this->getItemClass();

        foreach ($this->data as $data) {
            /** @var BaseListItem $item */
            $item = new $itemClass($data, $this);

            $this->add($item);
        }

        if ($this->isEmpty()) {
            $this->setTag('div');
            $this->add(new EmptyState(t('No items found.')));
        }
    }
}
