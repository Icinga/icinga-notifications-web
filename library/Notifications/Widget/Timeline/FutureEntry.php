<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use Icinga\Module\Notifications\Widget\TimeGrid\Entry;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Web\Widget\Icon;

class FutureEntry extends Entry
{
    public function getColor(int $transparency): string
    {
        return sprintf('~"hsl(166 90%% 50%% / %d%%)"', $transparency);
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
