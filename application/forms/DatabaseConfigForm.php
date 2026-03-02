<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Data\ResourceFactory;
use ipl\Web\Compat\CompatForm;

class DatabaseConfigForm extends CompatForm
{
    protected function assemble()
    {
        $dbResources = ResourceFactory::getResourceConfigs('db')->keys();

        $this->addElement(
            'select',
            'resource',
            [
                'label'       => $this->translate('Database'),
                'options'     => array_merge(
                    ['' => sprintf(' - %s - ', $this->translate('Please choose'))],
                    array_combine($dbResources, $dbResources)
                ),
                'disable'     => [''],
                'required'    => true,
                'value'       => ''
            ]
        );

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $this->translate('Save Changes')
            ]
        );
    }
}
