<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Form\Data;

class Source
{
    /**
     * @param ?int $id The primary database key value, NULL for new sources
     * @param string $type The source type
     * @param string $name The source name
     * @param ?string $listenerUsername The username the source uses to authenticate with the listener
     * @param ?string $listenerPassword The password the source will use during authentication, NULL for no change
     * @param ?string $clientCertificateSubject The expected subject of the source's client certificate
     * @param bool $locked Whether the source configuration is being managed by an integration
     */
    public function __construct(
        readonly public ?int $id,
        readonly public string $type,
        readonly public string $name,
        readonly public ?string $listenerUsername,
        public ?string $listenerPassword,
        readonly public ?string $clientCertificateSubject,
        readonly public bool $locked
    ) {
    }
}
