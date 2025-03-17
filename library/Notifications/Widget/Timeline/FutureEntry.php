<?php

namespace Icinga\Module\Notifications\Widget\Timeline;

use Icinga\Module\Notifications\Widget\TimeGrid\Entry;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\I18n\Translation;
use ipl\Web\Widget\Icon;

class FutureEntry extends Entry
{
    use Translation;

    public function getColor(int $transparency): string
    {
        return sprintf('~"hsl(%d %d%% 50%% / %d%%)"', 166, 90, $transparency);
    }

    protected function assembleContainer(BaseHtmlElement $container): void
    {
        $this
            ->addHtml(new Icon('angle-right'))
            ->addAttributes(new Attributes([
                'class' => 'future',
                'title' => $this->translate('rotation starts in the future')
            ]));
    }
}
