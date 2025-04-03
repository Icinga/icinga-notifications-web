<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\IncidentContact;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/** @implements ItemRenderer<IncidentContact> */
class IncidentContactRenderer implements ItemRenderer
{
    use Translation;

    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('incident-contact');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
        $visual->addHtml(new Icon($item->role === 'manager' ? Icons::USER_MANAGER : Icons::USER));
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $title->addHtml(new Link($item->full_name, Links::contact($item->id), ['class' => 'subject']));

        if ($item->role === 'manager') {
            $title->addHtml(new Text($this->translate('manages this incident')));
        }
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }
}
