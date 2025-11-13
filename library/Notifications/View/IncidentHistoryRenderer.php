<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\IncidentHistory;
use Icinga\Module\Notifications\Widget\IconBall;
use ipl\Html\Attributes;
use ipl\Html\FormattedString;
use ipl\Html\Html;
use ipl\Html\HtmlDocument;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Web\Common\ItemRenderer;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\TimeAgo;

/** @implements ItemRenderer<IncidentHistory> */
class IncidentHistoryRenderer implements ItemRenderer
{
    use Translation;

    public function assembleAttributes($item, Attributes $attributes, string $layout): void
    {
        $classes = ['incident-history'];
        if ($item->type === 'notified') {
            $classes[] = 'notification-state';
            if ($item->notification_state === 'suppressed') {
                $classes[] = 'suppressed';
            } elseif ($item->notification_state === 'failed') {
                $classes[] = 'failed';
            }
        }

        $attributes->get('class')->addValue($classes);
    }

    public function assembleVisual($item, HtmlDocument $visual, string $layout): void
    {
        $incidentIcon = $this->getIncidentEventIcon($item);
        if ($item->type === 'incident_severity_changed') {
            $content = new Icon($incidentIcon, ['class' => 'severity-' . $item->new_severity]);
        } else {
            $content = new IconBall($incidentIcon);
        }

        $visual->addHtml($content);
    }

    public function assembleTitle($item, HtmlDocument $title, string $layout): void
    {
    }

    public function assembleCaption($item, HtmlDocument $caption, string $layout): void
    {
        $caption->addHtml($this->buildMessage($item));
    }

    public function assembleExtendedInfo($item, HtmlDocument $info, string $layout): void
    {
        $info->addHtml(new TimeAgo($item->time->getTimestamp()));
    }

    public function assembleFooter($item, HtmlDocument $footer, string $layout): void
    {
    }

    public function assemble($item, string $name, HtmlDocument $element, string $layout): bool
    {
        return false; // no custom sections
    }

    /**
     * Get the icon for the incident event
     *
     * @param IncidentHistory $item
     *
     * @return string
     */
    protected function getIncidentEventIcon(IncidentHistory $item): string
    {
        return match ($item->type) {
            'opened'                    => Icons::OPENED,
            'muted'                     => Icons::MUTE,
            'unmuted'                   => Icons::UNMUTE,
            'incident_severity_changed' => $this->getSeverityIcon($item),
            'recipient_role_changed'    => $this->getRoleIcon($item),
            'closed'                    => Icons::CLOSED,
            'rule_matched'              => Icons::RULE_MATCHED,
            'escalation_triggered'      => Icons::TRIGGERED,
            'notified'                  => Icons::NOTIFIED,
            default                     => Icons::UNDEFINED
        };
    }

    /**
     * Get the icon for the new incident severity
     *
     * @param IncidentHistory $item
     *
     * @return string
     */
    protected function getSeverityIcon(IncidentHistory $item): string
    {
        return match ($item->new_severity) {
            'ok'      => Icons::OK,
            'warning' => Icons::WARNING,
            'err'     => Icons::ERROR,
            'crit'    => Icons::CRITICAL,
            default   => Icons::UNDEFINED
        };
    }

    /**
     * Get the icon for the incident recipient role
     *
     * @param IncidentHistory $item
     *
     * @return string
     */
    protected function getRoleIcon(IncidentHistory $item): string
    {
        switch ($item->new_recipient_role) {
            case 'manager':
                return Icons::MANAGE;
            case 'subscriber':
                return Icons::SUBSCRIBED;
            default:
                if ($item->old_recipient_role !== null) {
                    if ($item->old_recipient_role === 'manager') {
                        return Icons::UNMANAGE;
                    } else {
                        return Icons::UNSUBSCRIBED;
                    }
                }

                return Icons::UNDEFINED;
        }
    }

    /**
     * Build the message for the incident history item
     *
     * @param IncidentHistory $item
     *
     * @return ValidHtml
     */
    protected function buildMessage(IncidentHistory $item): ValidHtml
    {
        switch ($item->type) {
            case 'opened':
                $message = sprintf(
                    $this->translate('Incident opened at severity %s'),
                    Event::mapSeverity($item->new_severity)
                );

                break;
            case 'closed':
                $message = $this->translate('Incident closed');

                break;
            case "notified":
                if ($item->contactgroup_id) {
                    if ($item->notification_state === 'sent') {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of contact group %s'),
                            $item->contact->full_name,
                            $item->channel->type,
                            $item->contactgroup->name
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of contact group %s (%s)'),
                            $item->contact->full_name,
                            $item->channel->type,
                            $item->contactgroup->name,
                            IncidentHistory::translateNotificationState($item->notification_state)
                        );
                    }
                } elseif ($item->schedule_id) {
                    if ($item->notfication_state === 'sent') {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of schedule %s'),
                            $item->contact->full_name,
                            $item->channel->type,
                            $item->schedule->name
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of schedule %s (%s)'),
                            $item->contact->full_name,
                            $item->schedule->name,
                            $item->channel->type,
                            IncidentHistory::translateNotificationState($item->notification_state)
                        );
                    }
                } elseif ($item->notification_state === 'sent') {
                    $message = sprintf(
                        $this->translate('Contact %s notified via %s'),
                        $item->contact->full_name,
                        $item->channel->type
                    );
                } else {
                    $message = new FormattedString(
                        $this->translate('Contact %s notified via %s %s'),
                        [
                            $item->contact->full_name,
                            $item->channel->type,
                            Html::tag(
                                'span',
                                ['class' => 'state-text'],
                                sprintf('(%s)', IncidentHistory::translateNotificationState($item->notification_state))
                            )
                        ]
                    );
                }

                break;
            case 'incident_severity_changed':
                $message = sprintf(
                    $this->translate('Incident severity changed from %s to %s'),
                    Event::mapSeverity($item->old_severity),
                    Event::mapSeverity($item->new_severity)
                );

                break;
            case 'recipient_role_changed':
                $newRole = $item->new_recipient_role;
                $message = '';
                if ($newRole === 'manager' || (! $newRole && $item->old_recipient_role === 'manager')) {
                    if ($item->contact_id) {
                        $message = sprintf(
                            $this->translate('Contact %s %s managing this incident'),
                            $item->contact->full_name,
                            ! $item->new_recipient_role ? 'stopped' : 'started'
                        );
                    } elseif ($item->contactgroup_id) {
                        $message = sprintf(
                            $this->translate('Contact group %s %s managing this incident'),
                            $item->contactgroup->name,
                            ! $item->new_recipient_role ? 'stopped' : 'started'
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Schedule %s %s managing this incident'),
                            $item->schedule->name,
                            ! $item->new_recipient_role ? 'stopped' : 'started'
                        );
                    }
                } elseif (
                    $newRole === 'subscriber'
                    || (
                        ! $newRole && $item->old_recipient_role === 'subscriber'
                    )
                ) {
                    if ($item->contact_id) {
                        $message = sprintf(
                            $this->translate('Contact %s %s this incident'),
                            $item->contact->full_name,
                            ! $item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                        );
                    } elseif ($item->contactgroup_id) {
                        $message = sprintf(
                            $this->translate('Contact group %s %s this incident'),
                            $item->contactgroup->name,
                            ! $item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Schedule %s %s this incident'),
                            $item->schedule->name,
                            ! $item->new_recipient_role ? 'unsubscribed from' : 'subscribed to'
                        );
                    }
                }

                break;
            case 'rule_matched':
                $message = sprintf($this->translate('Rule %s matched on this incident'), $item->rule->name);

                break;
            case 'escalation_triggered':
                $message = sprintf(
                    $this->translate('Rule %s reached escalation %s'),
                    $item->rule->name,
                    $item->rule_escalation->name
                );

                break;
            case 'muted':
                $message = $this->translate('Notifications for this incident have been muted');

                break;
            case 'unmuted':
                $message = $this->translate('Notifications for this incident have been unmuted');

                break;
            default:
                $message = '';
        }

        $messageFromDb = $item->message ? ': ' . $item->message : '';

        if (is_string($message)) {
            $message = new Text($message . $messageFromDb);
        } else {
            $message = new FormattedString('%s %s', [$message, $messageFromDb]);
        }

        return $message;
    }
}
