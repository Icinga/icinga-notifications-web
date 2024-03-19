<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseListItem;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Model\Contact;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/**
 * Contact item of a contact list. Represents one database row.
 */
class IncidentContactListItem extends BaseListItem
{
    /** @var Contact The associated list item */
    protected $item;

    /** @var IncidentContactList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true);
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $iconName = $this->item->role === 'manager' ? Icons::USER_MANAGER : Icons::USER;
        $visual->add(new Icon($iconName));
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link(
            $this->item->full_name,
            Url::fromPath('notifications/contact', ['id' => $this->item->id]),
            ['class' => 'subject']
        ));

        if ($this->item->role === 'manager') {
            $title->addHtml(Html::tag('span', t('manages this incident')));
        }
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
    }
}
