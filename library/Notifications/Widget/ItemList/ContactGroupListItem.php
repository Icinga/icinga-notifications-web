<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contactgroup;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Link;

class ContactGroupListItem extends BaseListItem
{
    /** @var Contactgroup The associated list item */
    protected $item;

    /** @var ContactGroupList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true)
            ->set('data-icinga-detail-filter', Links::contactGroup($this->item->id)->getQueryString());
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $visual->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'contact-ball']),
            Text::create($this->item->name[0])
        ));
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->addHtml($this->createHeader());
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->addHtml($this->createTitle());
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link($this->item->name, Links::contactGroup($this->item->id), ['class' => 'subject']));
    }
}
