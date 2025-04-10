<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Web\Notification;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Web\Compat\CompatController;

class SourceController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        $sourceId = (int) $this->params->getRequired('id');

        $form = (new SourceForm(Database::get()))
            ->loadSource($sourceId)
            ->on(SourceForm::ON_SUCCESS, function (SourceForm $form) {
                /** @var FormSubmitElement $pressedButton */
                $pressedButton = $form->getPressedSubmitElement();
                if ($pressedButton->getName() === 'delete') {
                    $form->removeSource();
                    Notification::success(sprintf(
                        $this->translate('Deleted source "%s" successfully'),
                        $form->getSourceName()
                    ));
                } else {
                    $form->editSource();
                    Notification::success(sprintf(
                        $this->translate('Updated source "%s" successfully'),
                        $form->getSourceName()
                    ));
                }

                $this->switchToSingleColumnLayout();
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf($this->translate('Source: %s'), $form->getSourceName()));
        $this->addContent($form);
    }
}
