<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatController;

class ContactGroupController extends CompatController
{
    /** @var Connection $db */
    private $db;

    public function init()
    {
        $this->assertPermission('notifications/config/contact-groups');

        $this->db = Database::get();
    }

    public function editAction(): void
    {
        // TODO: add edit logic
        $groupId = $this->params->getRequired('id');
    }
}
