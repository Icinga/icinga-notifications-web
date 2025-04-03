<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Exception\NotImplementedError;
use Icinga\Module\Notifications\Model\Event;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\EventRenderer;
use Icinga\Module\Notifications\View\IncidentRenderer;
use ipl\Html\BaseHtmlElement;
use ipl\Orm\Model;
use ipl\Web\Layout\HeaderItemLayout;

/**
 * ObjectHeader
 *
 * Create a header
 *
 * @template Item of Event|Incident
 */
class ObjectHeader extends BaseHtmlElement
{
    /** @var Item */
    protected $object;

    protected $tag = 'div';

    /**
     * Create a new object header
     *
     * @param Item $object
     */
    public function __construct(Model $object)
    {
        $this->object = $object;
    }

    /**
     * @throws NotImplementedError When the object type is not supported
     */
    protected function assemble(): void
    {
        switch (true) {
            case $this->object instanceof Event:
                $renderer = new EventRenderer();

                break;
            case $this->object instanceof Incident:
                $renderer = new IncidentRenderer();

                break;
            default:
                throw new NotImplementedError('Not implemented');
        }

        $layout = new HeaderItemLayout($this->object, $renderer);

        $this->addAttributes($layout->getAttributes());
        $this->addHtml($layout);
    }
}
