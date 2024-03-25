<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\BaseListItem;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Widget\CustomIcon;
use Icinga\Module\Notifications\Widget\SourceIcon;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\I18n\Translation;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;

/**
 * Event item of an event list. Represents one database row.
 */
class EventListItem extends BaseListItem
{
    use Translation;

    /** @var Event The associated list item */
    protected $item;

    /** @var EventList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        if (! $this->list->getNoSubjectLink()) {
            $this->getAttributes()
                ->set('data-action-item', true);
        }
    }

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        $content = null;
        $severity = $this->item->severity;
        $class = 'severity-' . $severity;

        if ($this->item->type === 'internal') {
            /*
             * TODO(nc): Add proper handling of internal events once
             *  https://github.com/Icinga/icinga-notifications/issues/162 gets sorted out
             */
            $content = new CustomIcon('square-up-right', 'fa-solid');
        } else {
            switch ($severity) {
                case 'ok':
                    $content = (new Icon('heart', ['class' => $class]))->setStyle('fa-regular');
                    break;
                case 'crit':
                    $content = new Icon('circle-exclamation', ['class' => $class]);
                    break;
                case 'warning':
                    $content = new Icon('exclamation-triangle', ['class' => $class]);
                    break;
                case 'err':
                    $content = (new Icon('circle-xmark', ['class' => $class]))->setStyle('fa-regular');
                    break;
                case 'debug':
                    $content = new Icon('bug-slash');
                    break;
                case 'info':
                    $content = new Icon('info');
                    break;
                case 'alert':
                    $content = new Icon('bell');
                    break;
                case 'emerg':
                    $content = new Icon('tower-broadcast');
                    break;
                case 'notice':
                    $content = new Icon('envelope');
                    break;
            }
        }

        if ($content) {
            $visual->addHtml($content);
        }
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        if ($this->item->incident->id !== null) {
            $title->addHtml(Html::tag('span', [], sprintf('#%d:', $this->item->incident->id)));
        }

        /** @var Objects $obj */
        $obj = $this->item->object;
        $name = $obj->getName();
        if (! $this->list->getNoSubjectLink()) {
            $content = new Link($name, Links::event($this->item->id), ['class' => 'subject']);
        } else {
            $content = Html::tag('span', ['class' => 'subject'], $name);
        }

        $msg = null;
        if ($this->item->severity === null) {
            $description = strtolower(trim($this->item->message . '')) ?: '';
            /*
            * TODO(nc): strpos can be replaced with str_starts_with() once the minimal supported PHP version reaches
            *  8.0
            */
            if (strpos($description, 'incident reached age') === 0) {
                $msg = $this->translate('exceeded time constraint');
            } elseif (strpos($description, 'incident reevaluation') === 0) {
                $msg = $this->translate('was reevaluated at daemon startup');
            } else {
                $msg = $this->translate('was acknowledged');
            }
        } elseif ($this->item->severity === 'ok') {
            $msg = $this->translate('recovered');
        } else {
            $msg = $this->translate('ran into a problem');
        }

        $title->addHtml($content);
        $title->addHtml(Html::tag('span', ['class' => 'state'], $msg));
    }

    protected function assembleCaption(BaseHtmlElement $caption)
    {
        $caption->add($this->item->message);
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $content = [];
        if ($this->item->type !== 'internal') {
            /** @var Objects $object */
            $object = $this->item->object;
            /** @var Source $source */
            $source = $object->source;
            $content[] = (new SourceIcon(SourceIcon::SIZE_BIG))->addHtml($source->getIcon());
        }

        $content[] = new TimeAgo($this->item->time->getTimestamp());

        $header->add($this->createTitle());
        $header->add(
            Html::tag(
                'span',
                ['class' => 'meta'],
                $content
            )
        );
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
        $main->add($this->createCaption());
        $main->add($this->createFooter());
    }
}
