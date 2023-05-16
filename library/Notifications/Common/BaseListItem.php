<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Common;

use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Html\HtmlElement;

/**
 * Base class for list items
 */
abstract class BaseListItem extends BaseHtmlElement
{
    protected $baseAttributes = ['class' => 'list-item'];

    /** @var object The associated list item */
    protected $item;

    /** @var BaseItemList The list where the item is part of */
    protected $list;

    protected $tag = 'li';

    /**
     * Create a new list item
     *
     * @param object       $item
     * @param BaseItemList $list
     */
    public function __construct($item, BaseItemList $list)
    {
        $this->item = $item;
        $this->list = $list;

        $this->addAttributes($this->baseAttributes);

        $this->init();
    }

    /**
     * Initialize the list item
     *
     * If you want to adjust the list item after construction, override this method.
     */
    protected function init(): void
    {
    }

    abstract protected function assembleHeader(BaseHtmlElement $header): void;

    abstract protected function assembleMain(BaseHtmlElement $main): void;

    protected function assembleFooter(BaseHtmlElement $footer): void
    {
    }

    protected function assembleCaption(BaseHtmlElement $caption)
    {
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
    }

    protected function createCaption(): BaseHtmlElement
    {
        $caption = Html::tag('section', ['class' => 'caption']);

        $this->assembleCaption($caption);

        return $caption;
    }

    protected function createHeader(): BaseHtmlElement
    {
        $header = Html::tag('header');

        $this->assembleHeader($header);

        return $header;
    }

    protected function createMain(): BaseHtmlElement
    {
        $main = Html::tag('div', ['class' => 'main']);

        $this->assembleMain($main);

        return $main;
    }

    protected function createFooter(): BaseHtmlElement
    {
        $footer = new HtmlElement('footer');

        $this->assembleFooter($footer);

        return $footer;
    }

    protected function createTitle(): BaseHtmlElement
    {
        $title = HTML::tag('div', ['class' => 'title']);

        $this->assembleTitle($title);

        return $title;
    }

    protected function createVisual(): ?BaseHtmlElement
    {
        $visual = Html::tag('div', ['class' => 'visual']);

        $this->assembleVisual($visual);

        return $visual;
    }

    protected function assemble()
    {
        $this->add([
            $this->createVisual(),
            $this->createMain()
        ]);
    }
}
