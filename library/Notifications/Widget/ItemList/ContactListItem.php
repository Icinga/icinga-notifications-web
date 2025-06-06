<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Model\Contact;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

/**
 * Contact item of a contact list. Represents one database row.
 */
class ContactListItem extends BaseListItem
{
    /** @var Contact The associated list item */
    protected $item;

    /** @var ContactList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true);
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $visual->addHtml(new HtmlElement(
            'div',
            Attributes::create(['class' => 'contact-ball']),
            Text::create(grapheme_substr($this->item->full_name, 0, 1))
        ));
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link(
            $this->item->full_name,
            Url::fromPath('notifications/contact', ['id' => $this->item->id]),
            ['class' => 'subject']
        ));
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());

        $main->add($this->createFooter());
    }
}
