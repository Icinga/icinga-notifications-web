<?php

// SPDX-FileCopyrightText: 2025 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\Detail;

use Icinga\Exception\NotImplementedError;
use Icinga\Module\Notifications\Model\Contactgroup;
use Icinga\Module\Notifications\Model\Incident;
use Icinga\Module\Notifications\View\ContactgroupRenderer;
use Icinga\Module\Notifications\View\IncidentRenderer;
use ipl\Html\BaseHtmlElement;
use ipl\Orm\Model;
use ipl\Web\Layout\HeaderItemLayout;

/**
 * ObjectHeader
 *
 * Create a header
 *
 * @template Item of Incident|Contactgroup
 */
class ObjectHeader extends BaseHtmlElement
{
    /** @var Item */
    protected Model $object;

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

    protected function assemble(): void
    {
        $renderer = match (true) {
            $this->object instanceof Incident     => new IncidentRenderer(),
            $this->object instanceof Contactgroup => new ContactgroupRenderer()
        };

        $layout = new HeaderItemLayout($this->object, $renderer);

        $this->addAttributes($layout->getAttributes());
        $this->addHtml($layout);
    }
}
