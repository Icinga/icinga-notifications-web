<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget\ItemList;

use Icinga\Module\Noma\Common\Icons;
use Icinga\Module\Noma\Common\BaseListItem;
use Icinga\Module\Noma\Common\Links;
use Icinga\Module\Noma\Model\IncidentHistory;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use ipl\Web\Widget\TimeAgo;

/**
 * Event item of an event list. Represents one database row.
 */
class IncidentHistoryListItem extends BaseListItem
{
    /** @var IncidentHistory The associated list item */
    protected $item;

    /** @var IncidentHistoryList The list where the item is part of */
    protected $list;

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        if ($this->item->type === 'incident_severity_changed' || $this->item->type === 'source_severity_changed') {
            $content = new Icon($this->getIncidentEventIcon(), ['class' => 'severity-' . $this->item->new_severity]);
        } else {
            $content = new Icon($this->getIncidentEventIcon(), ['class' => 'type-' . $this->item->type]);

            switch ($this->item->type) {
                case 'closed':
                case 'rule_matched':
                case 'recipient_role_changed':
                case 'notified':
                    $content->setStyle('fa-regular');
            }
        }

        $visual->addHtml($content);
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        if ($this->item->event_id !== null) {
            $this->getAttributes()
                ->set('data-action-item', true);
            $event = $this->item->event;

            if ($event->object->service) {
                $content = Html::sprintf(
                    t('%s on %s', '<service> on <host>'),
                    new Link(
                        $event->object->host,
                        Links::event($this->item->event_id),
                        ['class' => 'subject']
                    ),
                    Html::tag('span', ['class' => 'subject'], $event->object->host)
                );
            } else {
                $content = new Link(
                    $event->object->host,
                    Links::event($this->item->event_id),
                    ['class' => 'subject']
                );
            }

            $title->addHtml($content);
        } elseif ($this->item->contact_id !== null) {
            $this->getAttributes()
                ->set('data-action-item', true);
            $content = new Link(
                $this->item->contact->full_name,
                Links::contact($this->item->contact_id),
                ['class' => 'subject']
            );

            $title->add([
                $content,
                Html::tag('span', ['class' => 'subject'], $this->item->message)
            ]);
        } else {
            $title->addHtml(Html::tag('span', ['class' => ['subject', 'caption']], $this->item->message));
        }
    }

    protected function assembleCaption(BaseHtmlElement $caption)
    {
        $caption->add($this->item->message);
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());

        if ($this->item->event_id !== null) {
            $header->add($this->createCaption());
            $header->add($this->item->event->source->getIcon());
        }

        $header->add(new TimeAgo($this->item->time->getTimestamp()));
    }

    protected function getIncidentEventIcon(): string
    {
        switch ($this->item->type) {
            case 'opened':
                return Icons::OPENED;
            case 'incident_severity_changed':
            case 'source_severity_changed':
                return $this->getSeverityIcon();
            case 'recipient_role_changed':
                return $this->getRoleIcon();
            case 'closed':
            case 'rule_matched':
                return Icons::OK;
            case 'escalation_triggered':
                return Icons::TRIGGERED;
            default:
                return Icons::NOTIFIED;
        }
    }

    protected function getSeverityIcon(): string
    {
        switch ($this->item->new_severity) {
            case 'ok':
                return Icons::OK;
            case 'err':
                return Icons::ERROR;
            case 'crit':
                return Icons::CRITICAL;
            default:
                return Icons::WARNING;
        }
    }

    protected function getRoleIcon(): ?string
    {
        switch ($this->item->new_recipient_role) {
            case 'manager':
                return Icons::MANAGE;
            case 'subscriber':
                return Icons::SUBSCRIBED;
            default:
                if ($this->item->old_recipient_role !== null) {
                    if ($this->item->old_recipient_role === 'manager') {
                        return Icons::UNMANAGE;
                    } else {
                        return Icons::UNSUBSCRIBED;
                    }
                }

                return '';
        }
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
    }
}
