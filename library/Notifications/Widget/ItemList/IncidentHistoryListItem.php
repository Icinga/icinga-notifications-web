<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Widget\IconBall;
use ipl\Html\BaseHtmlElement;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Icon;
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
        $incidentIcon = $this->getIncidentEventIcon();
        if ($this->item->type === 'incident_severity_changed') {
            $content = new Icon($incidentIcon, ['class' => 'severity-' . $this->item->new_severity]);
        } else {
            $content = new IconBall($incidentIcon);
        }

        $visual->addHtml($content);
    }

    protected function assembleCaption(BaseHtmlElement $caption): void
    {
        $caption->add($this->buildMessage());
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $title = $this->createTitle();
        if (! $title->isEmpty()) {
            $header->addHtml($title);
        }

        $header->addHtml($this->createCaption());

        $header->add(new TimeAgo($this->item->time->getTimestamp()));
    }

    protected function getIncidentEventIcon(): string
    {
        switch ($this->item->type) {
            case 'opened':
                return Icons::OPENED;
            case 'incident_severity_changed':
                return $this->getSeverityIcon();
            case 'recipient_role_changed':
                return $this->getRoleIcon();
            case 'closed':
                return Icons::CLOSED;
            case 'rule_matched':
                return Icons::RULE_MATCHED;
            case 'escalation_triggered':
                return Icons::TRIGGERED;
            case 'notified':
                return Icons::NOTIFIED;
            default:
                return Icons::UNDEFINED;
        }
    }

    protected function getSeverityIcon(): string
    {
        switch ($this->item->new_severity) {
            case 'ok':
                return Icons::OK;
            case 'warning':
                return Icons::WARNING;
            case 'err':
                return Icons::ERROR;
            case 'crit':
                return Icons::CRITICAL;
            default:
                return Icons::UNDEFINED;
        }
    }

    protected function getRoleIcon(): string
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

                return Icons::UNDEFINED;
        }
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
    }

    protected function buildMessage(): string
    {
        switch ($this->item->type) {
            case 'opened':
                $message = sprintf(
                    t('Incident opened at severity %s'),
                    Event::mapSeverity($this->item->new_severity)
                );

                break;
            case 'closed':
                $message = t('Incident closed');

                break;
            case "notified":
                if ($this->item->contactgroup_id) {
                    $message = sprintf(
                        t('Contact %s notified via %s as member of contact group %s'),
                        $this->item->contact->full_name,
                        $this->item->channel->type,
                        $this->item->contactgroup->name
                    );
                } elseif ($this->item->schedule_id) {
                    $message = sprintf(
                        t('Contact %s notified via %s as member of schedule %s'),
                        $this->item->contact->full_name,
                        $this->item->channel->type,
                        $this->item->schedule->name
                    );
                } else {
                    $message = sprintf(
                        t('Contact %s notified via %s'),
                        $this->item->contact->full_name,
                        $this->item->channel->type
                    );
                }

                break;
            case 'incident_severity_changed':
                $message = sprintf(
                    t('Incident severity changed from %s to %s'),
                    Event::mapSeverity($this->item->old_severity),
                    Event::mapSeverity($this->item->new_severity)
                );

                break;
            case 'recipient_role_changed':
                $newRole = $this->item->new_recipient_role;
                $message = '';
                if ($newRole === 'manager' || (! $newRole && $this->item->old_recipient_role === 'manager')) {
                    if ($this->item->contact_id) {
                        $message = sprintf(
                            t('Contact %s %s managing this incident'),
                            $this->item->contact->full_name,
                            ! $this->item->new_recipient_role ? 'stopped' : 'started'
                        );
                    } elseif ($this->item->contactgroup_id) {
                        $message = sprintf(
                            t('Contact group %s %s managing this incident'),
                            $this->item->contactgroup->name,
                            ! $this->item->new_recipient_role ? 'stopped' : 'started'
                        );
                    } else {
                        $message = sprintf(
                            t('Schedule %s %s managing this incident'),
                            $this->item->schedule->name,
                            ! $this->item->new_recipient_role ? 'stopped' : 'started'
                        );
                    }
                } elseif (
                    $newRole === 'subscriber'
                    || (
                        ! $newRole && $this->item->old_recipient_role === 'subscriber'
                    )
                ) {
                    if ($this->item->contact_id) {
                        $message = sprintf(
                            t('Contact %s %s this incident'),
                            $this->item->contact->full_name,
                            ! $this->item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                        );
                    } elseif ($this->item->contactgroup_id) {
                        $message = sprintf(
                            t('Contact group %s %s this incident'),
                            $this->item->contactgroup->name,
                            ! $this->item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                        );
                    } else {
                        $message = sprintf(
                            t('Schedule %s %s this incident'),
                            $this->item->schedule->name,
                            ! $this->item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                        );
                    }
                }

                break;
            case 'rule_matched':
                $message = sprintf(t('Rule %s matched on this incident'), $this->item->rule->name);

                break;
            case 'escalation_triggered':
                $message = sprintf(
                    t('Rule %s reached escalation %s'),
                    $this->item->rule->name,
                    $this->item->rule_escalation->name
                );

                break;
            default:
                $message = '';
        }

        if ($this->item->message) {
            $message = $message === '' ? $this->item->message : $message . ': ' . $this->item->message;
        }

        return $message;
    }
}
