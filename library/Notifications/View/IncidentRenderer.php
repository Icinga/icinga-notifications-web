<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\Attributes;
use ipl\Html\FormattedString;
use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Ball;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;
use ipl\Web\Widget\TimeSince;

/** @implements ItemRenderer<Incident> */
class IncidentRenderer implements ItemRenderer
{
    use Translation;

    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('incident');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
        [$icon, $title] = match ($item->severity) {
            'ok'      => [Icons::SEVERITY_OK, $this->translate('Ok')],
            'warning' => [Icons::SEVERITY_WARN, $this->translate('Warning')],
            'err'     => [Icons::SEVERITY_ERR, $this->translate('Error')],
            'crit'    => [Icons::SEVERITY_CRIT, $this->translate('Critical')],
            'debug'   => [Icons::SEVERITY_DEBUG, $this->translate('Debug')],
            'info'    => [Icons::SEVERITY_INFO, $this->translate('Info')],
            'alert'   => [Icons::SEVERITY_ALERT, $this->translate('Alert')],
            'emerg'   => [Icons::SEVERITY_EMERG, $this->translate('Emergency')],
            'notice'  => [Icons::SEVERITY_NOTICE, $this->translate('Notice')],
            default   => [Icons::UNDEFINED, $this->translate('Undefined')]
        };

        $content = new Icon($icon, [
            'class' => ['severity-' . $item->severity],
            'title' => sprintf('%s: %s', $this->translate('Severity'), $title)
        ]);

        if ($item->severity === 'ok' || $item->severity === 'err') {
            $content->setStyle('fa-regular');
        }

        $visual->addHtml($content);
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        $title->addHtml(Html::tag('span', [], sprintf('#%d:', $item->id)));

        if ($layout === 'header') {
            $content = new HtmlElement('span', Attributes::create(['class' => 'subject']));
        } else {
            $content = new Link(null, Links::incident($item->id), ['class' => 'subject']);
        }

        /** @var Objects $obj */
        $obj = $item->object;
        $name = $obj->getName();

        $content->addAttributes($name->getAttributes());
        $content->addFrom($name);

        $title->addHtml($content);
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        if ($item->severity !== 'ok' && $item->mute_reason !== null) {
            $info->addHtml(new Icon(Icons::MUTE, ['title' => $item->mute_reason]));
        }

        /** @var Source $source */
        $source = $item->object->source;
        $info->addHtml(
            (new Ball(Ball::SIZE_BIG))
                ->addAttributes(Attributes::create(['class' => 'source-icon', 'title' => $source->name]))
                ->addHtml($source->getIcon())
        );

        if ($item->recovered_at !== null) {
            $info->addHtml(FormattedString::create(
                $this->translate('closed %s', '(incident) ... <relative time>'),
                new TimeAgo($item->recovered_at->getTimestamp())
            ));
        } else {
            $info->addHtml(new TimeSince($item->started_at->getTimestamp()));
        }
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }
}
