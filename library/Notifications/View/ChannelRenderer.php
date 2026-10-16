<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Channel;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\Text;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/** @implements ItemRenderer<Channel> */
class ChannelRenderer implements ItemRenderer
{
    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('channel');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
        if (isset($item->validation_result)) {
            $visual->addHtml(new Icon('circle-xmark', ['class' => 'error']));
        } else {
            $visual->addHtml(new Icon('check'));
        }
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $title->addHtml(new Link($item->name, Links::channel($item->id), ['class' => 'subject']));
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
        if (isset($item->validation_result)) {
            $caption->addHtml(Text::create($item->validation_result));
        }
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
