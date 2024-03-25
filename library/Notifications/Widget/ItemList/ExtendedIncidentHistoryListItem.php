<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Model\Objects;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Module\Notifications\Widget\CustomIcon;
use Icinga\Module\Notifications\Widget\SourceIcon;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\I18n\Translation;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\TimeAgo;

class ExtendedIncidentHistoryListItem extends BaseListItem
{
    use Translation;

    /** @var IncidentHistory */
    protected $item;

    /** @var ExtendedIncidentHistoryList */
    protected $list;

    protected function assembleVisual(BaseHtmlElement $visual): void
    {
        if (property_exists($this->item, 'isSourceEvent')) {
            /** @var Event $event */
            $event = $this->item->event;

            if ($event->type === 'state') {
                $icon = new Icon(
                    $this->getSeverityIcon($event->severity),
                    ['class' => 'severity-' . $event->severity]
                );
            } elseif ($event->type === 'internal') {
                $icon = new CustomIcon(Icons::TRIGGERED, 'fa-solid');
            } else {
                $icon = (new Icon(Icons::TRIGGERED))->setStyle('fa-solid');
            }
        } else {
            switch ($this->item->type) {
                case 'incident_severity_changed':
                    $icon = new Icon($this->getSeverityIcon());
                    break;
                case 'rule_matched':
                    $icon = new Icon(Icons::RULE_MATCHED, ['class' => 'type-' . $this->item->type]);
                    break;
                case 'opened':
                    $icon = (new Icon(Icons::OPENED))->setStyle('fa-regular');
                    break;
                case 'closed':
                    $icon = (new Icon(Icons::CLOSED))->setStyle('fa-regular');
                    break;
                case 'recipient_role_changed':
                    $icon = (new Icon($this->getRoleIcon()))->setContent('fa-regular');
                    break;
                case 'notified':
                    $icon = (new Icon(Icons::NOTIFIED))->setStyle('fa-regular');
                    break;
                default:
                    $icon = (new Icon(Icons::NOTIFIED))->setStyle('fa-regular');
            }
        }

        $visual->addHtml($icon);
    }

    protected function getSeverityIcon(?string $severity = null): string
    {
        $severity = $severity ?: $this->item->new_severity;
        switch ($severity) {
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

                return '';
        }
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $title = $this->createTitle();
        if (! $title->isEmpty()) {
            $header->addHtml($title);
        }

        $header->addHtml($this->createCaption());
        if (property_exists($this->item, 'isSourceEvent') && $this->item->type !== 'internal') {
            /** @var Event $event */
            $event = $this->item->event;
            /** @var Objects $object */
            $object = $event->object;
            /** @var Source $source */
            $source = $object->source;
            $header->add(
                (new SourceIcon(SourceIcon::SIZE_BIG))->addHtml($source->getIcon())
            );
        }

        $header->add(new TimeAgo($this->item->time->getTimestamp()));
    }

    protected function assembleCaption(BaseHtmlElement $caption): void
    {
        $caption->add($this->buildMessage());
    }

    private function buildMessage(): string
    {
        if (property_exists($this->item, 'isSourceEvent')) {
            /** @var Event $event */
            $event = $this->item->event;

            switch ($this->item->type) {
                case 'state':
                    $message = $event->message ?: '';
                    break;
                case 'internal':
                    $text = strtolower(trim($event->message . '')) ?: '';
                    /*
                    * TODO(nc): strpos can be replaced with str_starts_with() once the minimal supported PHP version
                    *  reaches 8.0
                    */
                    if (strpos($text, 'incident reached age') === 0) {
                        $message = $this->translate('Exceeded time constraint');
                    } elseif (strpos($text, 'incident reevaluation') === 0) {
                        $message = $this->translate('Reevaluated at daemon startup');
                    } else {
                        $message = $this->translate('Acknowledged');
                    }

                    break;
                default:
                    $message = '';
            }
        } else {
            switch ($this->item->type) {
                case 'opened':
                    $message = $this->translate('Incident opened');
                    break;
                case 'closed':
                    $message = $this->translate('Incident closed');
                    break;
                case "notified":
                    if ($this->item->contactgroup_id) {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of contact group %s'),
                            $this->item->contact->full_name,
                            $this->item->channel->type,
                            $this->item->contactgroup->name
                        );
                    } elseif ($this->item->schedule_id) {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of schedule %s'),
                            $this->item->contact->full_name,
                            $this->item->channel->type,
                            $this->item->schedule->name
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s'),
                            $this->item->contact->full_name,
                            $this->item->channel->type
                        );
                    }
                    break;
                case 'incident_severity_changed':
                    $message = sprintf(
                        $this->translate('Incident severity changed from %s to %s'),
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
                                $this->translate('Contact %s %s managing this incident'),
                                $this->item->contact->full_name,
                                ! $this->item->new_recipient_role ? 'stopped' : 'started'
                            );
                        } elseif ($this->item->contactgroup_id) {
                            $message = sprintf(
                                $this->translate('Contact group %s %s managing this incident'),
                                $this->item->contactgroup->name,
                                ! $this->item->new_recipient_role ? 'stopped' : 'started'
                            );
                        } else {
                            $message = sprintf(
                                $this->translate('Schedule %s %s managing this incident'),
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
                                $this->translate('Contact %s %s this incident'),
                                $this->item->contact->full_name,
                                ! $this->item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                            );
                        } elseif ($this->item->contactgroup_id) {
                            $message = sprintf(
                                $this->translate('Contact group %s %s this incident'),
                                $this->item->contactgroup->name,
                                ! $this->item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                            );
                        } else {
                            $message = sprintf(
                                $this->translate('Schedule %s %s this incident'),
                                $this->item->schedule->name,
                                ! $this->item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                            );
                        }
                    }

                    break;
                case 'rule_matched':
                    $message = sprintf($this->translate('Rule %s matched on this incident'), $this->item->rule->name);
                    break;
                case 'escalation_triggered':
                    $message = sprintf(
                        $this->translate('Rule %s reached escalation %s'),
                        $this->item->rule->name,
                        $this->item->rule_escalation->name
                    );
                    break;
                default:
                    $message = '';
            }
        }

        return $message;
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
    }

    protected function assembleFooter(BaseHtmlElement $footer): void
    {
        $footer->addHtml(HtmlElement::create('p', null, 'Footer'));
    }
}
