<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\View;

use Icinga\Module\Notifications\Common\Icons;
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
        if ($item->type === 'incident_severity_changed') {
            $content = $item->new_severity->getIcon();
        } else {
            $content = new IconBall(match ($item->type) {
                'opened'                    => Icons::OPENED,
                'muted'                     => Icons::MUTE,
                'unmuted'                   => Icons::UNMUTE,
                'recipient_role_changed'    => $this->getRoleIcon($item),
                'closed'                    => Icons::CLOSED,
                'rule_matched'              => Icons::RULE_MATCHED,
                'escalation_triggered'      => Icons::TRIGGERED,
                'notified'                  => Icons::NOTIFIED,
                default                     => Icons::UNDEFINED
            });
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
                    $item->new_severity->getLabel()
                );

                break;
            case 'closed':
                $message = $this->translate('Incident closed');

                break;
            case "notified":
                if (isset($item->contactgroup->name) && isset($item->contact->full_name)) {
                    if (isset($item->channel->type)) {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of contact group %s'),
                            $item->contact->full_name,
                            $item->channel->type,
                            $item->contactgroup->name
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Contact %s notified as member of contact group %s'),
                            $item->contact->full_name,
                            $item->contactgroup->name
                        );
                    }
                } elseif (isset($item->schedule->name) && isset($item->contact->full_name)) {
                    if (isset($item->channel->type)) {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s as member of schedule %s'),
                            $item->contact->full_name,
                            $item->channel->type,
                            $item->schedule->name
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Contact %s notified as member of schedule %s'),
                            $item->contact->full_name,
                            $item->schedule->name
                        );
                    }
                } elseif (isset($item->contact->full_name)) {
                    if (isset($item->channel->type)) {
                        $message = sprintf(
                            $this->translate('Contact %s notified via %s'),
                            $item->contact->full_name,
                            $item->channel->type
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Contact %s notified'),
                            $item->contact->full_name
                        );
                    }
                } else {
                    if (isset($item->channel->type)) {
                        $message = sprintf(
                            $this->translate('Unknown recipient notified via %s'),
                            $item->channel->type
                        );
                    } else {
                        $message = $this->translate('Unknown recipient notified');
                    }
                }

                if ($item->notification_state !== 'sent') {
                    $message = new FormattedString(
                        '%s (%s)',
                        [
                            $message,
                            Html::tag(
                                'span',
                                ['class' => 'state-text'],
                                IncidentHistory::translateNotificationState($item->notification_state)
                            )
                        ]
                    );
                }

                break;
            case 'incident_severity_changed':
                $message = sprintf(
                    $this->translate('Incident severity changed from %s to %s'),
                    $item->old_severity->getLabel(),
                    $item->new_severity->getLabel()
                );

                break;
            case 'recipient_role_changed':
                $newRole = $item->new_recipient_role;
                $message = '';
                if ($newRole === 'manager' || (! $newRole && $item->old_recipient_role === 'manager')) {
                    if (isset($item->contact->full_name)) {
                        $message = ! $newRole
                            ? sprintf(
                                $this->translate('Contact %s stopped managing this incident'),
                                $item->contact->full_name
                            )
                            : sprintf(
                                $this->translate('Contact %s started managing this incident'),
                                $item->contact->full_name
                            );
                    } else {
                        $message = ! $newRole
                            ? $this->translate('Unknown recipient stopped managing this incident')
                            : $this->translate('Unknown recipient started managing this incident');
                    }
                } elseif (
                    $newRole === 'subscriber'
                    || (
                        ! $newRole && $item->old_recipient_role === 'subscriber'
                    )
                ) {
                    if (isset($item->contact->full_name)) {
                        $message = ! $newRole
                            ? sprintf(
                                $this->translate('Contact %s unsubscribed from this incident'),
                                $item->contact->full_name
                            )
                            : sprintf(
                                $this->translate('Contact %s subscribed to this incident'),
                                $item->contact->full_name
                            );
                    } else {
                        $message = ! $newRole
                            ? $this->translate('Unknown recipient unsubscribed from this incident')
                            : $this->translate('Unknown recipient subscribed to this incident');
                    }
                }

                break;
            case 'rule_matched':
                if (isset($item->rule->name)) {
                    $message = sprintf($this->translate('Rule %s matched on this incident'), $item->rule->name);
                } else {
                    $message = $this->translate('Unknown rule matched on this incident');
                }

                break;
            case 'escalation_triggered':
                if (isset($item->rule->name)) {
                    if (isset($item->rule_escalation->name)) {
                        $message = sprintf(
                            $this->translate('Rule %s reached escalation %s'),
                            $item->rule->name,
                            $item->rule_escalation->name
                        );
                    } else {
                        $message = sprintf(
                            $this->translate('Rule %s reached unknown escalation'),
                            $item->rule->name
                        );
                    }
                } else {
                    $message = $this->translate('Unknown rule reached escalation');
                }

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
