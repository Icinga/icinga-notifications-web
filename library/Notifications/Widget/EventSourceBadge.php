<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget;

use Icinga\Module\Noma\Model\Source;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;

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

        $this->add((new SourceIcon(SourceIcon::SIZE_LARGE))->addHtml($this->source->getIcon()));
        $this->add(Html::tag('span', ['class' => 'name'], $this->source->name ?? $this->source->type));
    }
}
