<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Model\Source;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Web\Widget\Ball;

class EventSourceBadge extends BaseHtmlElement
{
    protected Source $source;

    protected $tag = 'span';

    protected $defaultAttributes = ['class' => 'event-source-badge'];

    /**
     * Create an event source badge with source icon
     *
     * @param Source $source
     */
    public function __construct(Source $source)
    {
        $this->source = $source;
    }

    protected function assemble(): void
    {
        $this
            ->addAttributes(Attributes::create([
                'title' => sprintf('%s (%s)', $this->source->name, $this->source->type)
            ]))
            ->addHtml(
                (new Ball(Ball::SIZE_LARGE))
                    ->addAttributes(Attributes::create(['class' => 'source-icon']))
                    ->addHtml($this->source->getIcon()),
                new HtmlElement('span', Attributes::create(['class' => 'name']), Text::create($this->source->name))
            );
    }
}
