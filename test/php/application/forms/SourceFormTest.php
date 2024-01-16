<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Tests\Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Forms\SourceForm;
use PHPUnit\Framework\TestCase;

class SourceFormTest extends TestCase
{
    public function testWhetherTheUsedHashAlgorithmIsStillTheDefault()
    {
        $this->assertSame(
            PASSWORD_DEFAULT,
            SourceForm::HASH_ALGORITHM,
            'PHP\'s default password hash algorithm changed. Consider adding support for it'
        );
    }
}
