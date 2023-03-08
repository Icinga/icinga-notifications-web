<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\NoMa\Controllers;

use Icinga\Authentication\Auth;
use Icinga\User;
use ipl\Web\Compat\CompatController;

class ApiV1Controller extends CompatController
{
    public function objectFilterAction(): void
    {
        $this->assertHttpMethod('get');

        if (! $this->getRequest()->isApiRequest()) {
            $this->httpBadRequest('Can only respond with JSON');
        }

        $user = new User($this->params->getRequired('username'));
        Auth::getInstance()->setupUser($user);
        $data = $user->getRestrictions('noma/filter/objects');

        $this->getResponse()
            ->json()
            ->setSuccessData($data)
            ->sendResponse();
    }
}
