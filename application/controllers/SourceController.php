<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\DeleteSourceForm;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Contract\Form;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;

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
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->loadSource($sourceId)
            ->on(Form::ON_SUBMIT, function (SourceForm $form): never {
                Database::get()->transaction(fn () => $form->editSource());
                Notification::success(sprintf(
                    $this->translate('Updated source "%s" successfully'),
                    $form->getSourceName()
                ));

                $this->switchToSingleColumnLayout();
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf($this->translate('Source: %s'), $form->getSourceName()));
        $this->addContent($form);
    }

    public function deleteAction(): void
    {
        $sourceId = (int) $this->params->getRequired('id');

        $form = (new DeleteSourceForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->loadSource($sourceId)
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function (DeleteSourceForm $form): never {
                Database::get()->transaction(fn (Connection $db) => $form->removeSource($db));
                Notification::success($this->translate('Deleted source successfully'));
                $this->switchToSingleColumnLayout();
            })
            ->handleRequest($this->getServerRequest());

        $this->setTitle(sprintf($this->translate('Delete Source: %s'), $form->getSourceName()));
        $this->addContent($form);
    }
}
