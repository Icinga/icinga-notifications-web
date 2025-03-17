<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use Icinga\Module\Notifications\Widget\TimeGrid\Entry;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Icon;

/**
 * FutureEntry
 *
 * Visualize a future entry of the rotation
 *
 * @extends Entry<0>
 */
class FutureEntry extends Entry
{
    public function __construct()
    {
        parent::__construct(0);

        $this->setContinuationType(Entry::TO_NEXT_GRID);
    }

    public function getColor(int $transparency): string
    {
        //  --base-disabled (#d0d3da) -> hsl(222, 12%, 84%) + transparency
        return sprintf('~"hsl(222 12%% 84%% / %d%%)"', $transparency);
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
        $futureBadge = new HtmlElement(
            'div',
            new Attributes([
                'title' => $this->translate('Rotation starts in the future'),
                $container->getAttributes()->get('class')
            ]),
            new Icon('angle-right')
        );

        $container
            ->setAttribute('class', 'future-entry') // override the default class
            ->addHtml($futureBadge);
    }
}
