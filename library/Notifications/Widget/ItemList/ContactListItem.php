<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseListItem;
use Icinga\Module\Notifications\Model\Contact;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
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
            Text::create($this->item->full_name[0])
        ));
    }

    protected function assembleFooter(BaseHtmlElement $footer): void
    {
        $contactIcons = new HtmlElement('div', Attributes::create(['class' => 'contact-icons']));
        if ($this->item->has_email) {
            $contactIcons->addHtml(new Icon('envelope'));
        }

        if ($this->item->has_rc) {
            $contactIcons->addHtml(new Icon('comment-dots'));
        }

        $footer->addHtml($contactIcons);
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
