<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use Icinga\Module\Notifications\Widget\TimeGrid\Entry;
use ipl\Html\BaseHtmlElement;

/**
 * @internal Reserved for internal use.
 */
final class FakeEntry extends Entry
{
    public function __construct()
    {
        parent::__construct(0);
    }

    public function getColor(int $transparency): string
    {
        return '';
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
    }

    public function renderUnwrapped(): string
    {
        return '';
    }
}
