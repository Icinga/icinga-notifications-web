<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Icinga\Module\Notifications\Repository\SourceRepository;
use PHPUnit\Framework\TestCase;

class SourceRepositoryTest extends TestCase
{
    public function testWhetherTheUsedHashAlgorithmIsStillTheDefault()
    {
        $this->assertSame(
            PASSWORD_DEFAULT,
            SourceRepository::HASH_ALGORITHM,
            'PHP\'s default password hash algorithm changed. Consider adding support for it'
        );
    }
}
