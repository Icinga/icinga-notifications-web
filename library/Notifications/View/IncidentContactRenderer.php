<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Auth;
use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\IncidentContact;
use Icinga\Module\Notifications\Model\Schedule;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/** @implements ItemRenderer<IncidentContact> */
class IncidentContactRenderer implements ItemRenderer
{
    use Translation;

    /** @var bool Whether the rendered item should not include a link to the contact */
    private bool $disableContactLink = false;
    private bool $disableContactGroupLink = false;
    private bool $disableScheduleLink = false;

    /**
     * Set whether the rendered item should not include a link to the contact
     *
     * @param bool $disableLink
     *
     * @return $this
     */
    public function disableContactLink(bool $disableLink): static
    {
        $this->disableContactLink = $disableLink;

        return $this;
    }

    public function disableContactGroupLink(bool $disableLink): static
    {
        $this->disableContactGroupLink = $disableLink;

        return $this;
    }

    public function disableScheduleLink(bool $disableLink): static
    {
        $this->disableScheduleLink = $disableLink;

        return $this;
    }

    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('incident-contact');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {

        [$icon, $title] = match (true) {
            $item->role === 'manager' => [new Icon(Icons::USER_MANAGER), $this->translate("Manager")],
            $item instanceof Contact => [new Icon(Icons::USER), $this->translate("User")],
            $item instanceof Contactgroup => [new Icon(Icons::CONTACTGROUP), $this->translate("Contact Group")],
            $item instanceof Schedule => [new Icon(Icons::SCHEDULE), $this->translate("Schedule")],
        };

        $icon->setAttribute('title', sprintf("[%s] %s", $title, $item->name ?? $item->full_name));

        $visual->addHtml($icon);
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $name = $item->full_name ?? $item->name;
        $link = match (true) {
            $item instanceof Contact && ! $this->disableContactLink => Links::contact($item->id),
            $item instanceof Contactgroup && ! $this->disableContactLink => Links::contactGroup($item->id),
            $item instanceof Schedule && ! $this->disableScheduleLink => Links::schedule($item->id),
            default => null
        };

        if ($link === null) {
            $title->addHtml(new HtmlElement(
                'span',
                Attributes::create(['class' => 'subject']),
                Text::create($name)
            ));
        } else {
            $title->addHtml(new Link($name, $link, ['class' => 'subject']));
        }

        if ($item->role === 'manager') {
            $title->addHtml(new Text($this->translate(' ' . 'manages this incident')));
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
