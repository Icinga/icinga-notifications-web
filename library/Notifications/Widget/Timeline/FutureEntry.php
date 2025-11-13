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
 */
class FutureEntry extends Entry
{
    protected $defaultAttributes = ['class' => 'future-entry'];

    protected ?string $continuationType = Entry::TO_NEXT_GRID;

    public function __construct()
    {
        parent::__construct(0);
    }

    public function getColor(int $transparency): string
    {
        return ''; // No user, no color, CSS will handle it
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
        $container->addHtml(new HtmlElement(
            'div',
            new Attributes([
                'title' => $this->translate('Rotation starts in the future')
            ]),
            new Icon('angle-right'),
            new HtmlElement('span', new Attributes(['class' => 'outline'])),
            new HtmlElement('span', new Attributes(['class' => 'outline'])),
            new HtmlElement('span', new Attributes(['class' => 'outline']))
        ));
    }
}
