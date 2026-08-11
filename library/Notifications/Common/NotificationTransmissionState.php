<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use Icinga\Module\Notifications\Widget\IconBall;
use ipl\Html\Attributes;

/**
 * Notification transmission state
 *
 * Each case maps to the backing string stored as notification transmission state.
 * Register {@see \ipl\Orm\Behavior\EnumCast} on a model to have those columns hydrated automatically as enum instances.
 */
enum NotificationTransmissionState: string
{
    case SENT = 'sent';
    case FAILED = 'failed';
    case PENDING = 'pending';

    /**
     * Get the backing string value
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the translated label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SENT       => t('Successfully sent notification'),
            self::FAILED     => t('Failed to send notification'),
            self::PENDING    => t('Pending notification'),
        };
    }

    /**
     * Get the icon
     *
     * @return IconBall
     */
    public function getIcon(): IconBall
    {
        return (new IconBall('paper-plane'))
            ->addAttributes(Attributes::create([
                'title' => $this->getLabel(),
                'class' => match ($this) {
                    self::SENT       => 'sent',
                    self::FAILED     => 'failed',
                    self::PENDING    => 'pending',
                }
            ]));
    }
}
