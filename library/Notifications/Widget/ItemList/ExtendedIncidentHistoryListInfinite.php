<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\LoadMore;
use ipl\Orm\ResultSet;

class ExtendedIncidentHistoryListInfinite extends ExtendedIncidentHistoryList
{
    use LoadMore;

    protected function init(): void
    {
        if ($this->data instanceof ResultSet) {
            $this->data = $this->getIterator($this->data);
        }

        parent::init();
    }
}
