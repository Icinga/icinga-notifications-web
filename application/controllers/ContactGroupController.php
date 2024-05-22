<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatController;

class ContactGroupController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('notifications/config/contact-groups');
    }

    public function editAction(): void
    {
        // TODO: add edit logic
        $groupId = $this->params->getRequired('id');
    }
}
