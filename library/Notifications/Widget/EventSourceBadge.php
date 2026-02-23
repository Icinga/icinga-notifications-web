<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Model\Source;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Widget\Ball;

class EventSourceBadge extends BaseHtmlElement
{
    /** @var Source */
    protected $source;

    protected $tag = 'span';

    protected $defaultAttributes = ['class' => 'event-source-badge'];

    /**
     * Create an event source badge with source icon
     *
     * @param Source    $source
     */
    public function __construct(Source $source)
    {
        $this->source = $source;
    }

    protected function assemble()
    {
        if ($this->source->name === null) {
            $title = $this->source->type;
        } else {
            $title = sprintf('%s (%s)', $this->source->name, $this->source->type);
        }

        $this
            ->getAttributes()
            ->add('title', $title);

        $this->addHtml((new Ball(Ball::SIZE_LARGE))
            ->addAttributes(['class' => 'source-icon'])
            ->addHtml($this->source->getIcon()));
        $this->add(Html::tag('span', ['class' => 'name'], $this->source->name ?? $this->source->type));
    }
}
