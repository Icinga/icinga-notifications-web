<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use InvalidArgumentException;
use ipl\Html\Attributes;
use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Ball;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;

/** @implements ItemRenderer<Event> */
class EventRenderer implements ItemRenderer
{
    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $attributes->get('class')->addValue('event');
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
        $icon = $item->getIcon();
        if ($icon) {
            $visual->addHtml($icon);
        }
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
        if (! $item->incident instanceof Incident) {
            throw new InvalidArgumentException('Incidents must be loaded with the event');
        }

        if ($item->incident->id !== null) {
            $title->addHtml(Html::tag('span', [], sprintf('#%d:', $item->incident->id)));
        }

        if ($layout === 'header') {
            $content = new HtmlElement('span', Attributes::create(['class' => 'subject']));
        } else {
            $content = new Link(null, Links::event($item->id), ['class' => 'subject']);
        }

        /** @var Objects $obj */
        $obj = $item->object;
        $name = $obj->getName();

        $content->addAttributes($name->getAttributes());
        $content->addFrom($name);

        $title->addHtml($content);
        $title->addHtml(HtmlElement::create('span', ['class' => 'state'], $item->getTypeText()));
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
        switch ($item->type) {
            case 'mute':
            case 'unmute':
            case 'flapping-start':
            case 'flapping-end':
            case 'downtime-start':
            case 'downtime-end':
            case 'downtime-removed':
            case 'acknowledgement-set':
            case 'acknowledgement-cleared':
                if ($item->mute_reason !== null) {
                    $caption->add($item->mute_reason);
                    break;
                }

            // Sometimes these events have no mute reason, but a message
            default:
                $caption->add($item->message);
        }
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        if ($item->type === 'state') {
            /** @var Objects $object */
            $object = $item->object;
            /** @var Source $source */
            $source = $object->source;
            $info->addHtml(
                (new Ball(Ball::SIZE_BIG))
                    ->addAttributes(['class' => 'source-icon'])
                    ->addHtml($source->getIcon())
            );
        }

        $info->addHtml(new TimeAgo($item->time->getTimestamp()));
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }
}
