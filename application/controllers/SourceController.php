<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\DeleteSourceForm;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Module\Notifications\Repository\SourceRepository;
use Icinga\Web\Notification;
use Icinga\Web\Session;
use ipl\Html\Contract\Form;
use ipl\Sql\Connection;
use ipl\Web\Compat\CompatController;
use ipl\Web\Url;
use RuntimeException;

class SourceController extends CompatController
{
    public function init(): void
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        (new SourceForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->on(Form::ON_REQUEST, function ($_, SourceForm $form) {
                $source = (new SourceRepository(Database::get()))
                    ->find((int) $this->params->getRequired('id'));
                if ($source === null) {
                    $this->httpNotFound($this->translate('Source not found'));
                }

                $form->setSource($source);

                $this->addTitleTab(sprintf($this->translate('Source: %s'), $source->name));
                $this->addContent($form);
            })
            ->on(Form::ON_SUBMIT, function (SourceForm $form): never {
                $source = $form->getSource();

                if ($source->locked) {
                    throw new RuntimeException('Source is locked');
                }

                Database::get()->transaction(fn(Connection $db) => (new SourceRepository($db))->update($source));
                Notification::success(sprintf(
                    $this->translate('Updated source "%s" successfully'),
                    $source->name
                ));

                $this->switchToSingleColumnLayout();
            })->on(Form::ON_SENT, function (SourceForm $form) {
                // TODO: I feel this should be part of CompatForm or CompatController (e.g. $this->sendForm())
                if (! $this->getResponse()->isRedirect()) {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })->handleRequest($this->getServerRequest());
    }

    public function deleteAction(): void
    {
        $sourceId = (int) $this->params->getRequired('id');

        (new DeleteSourceForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_REQUEST, function ($_, DeleteSourceForm $form) use ($sourceId) {
                $sourceRepository = new SourceRepository(Database::get());
                $source = $sourceRepository->find($sourceId);
                if ($source === null) {
                    $this->httpNotFound($this->translate('Source not found'));
                }

                $this->setTitle(sprintf($this->translate('Delete Source: %s'), $source->name));
                $form->setLocked($source->locked)
                    ->setLastOfItsType($sourceRepository->isLastOfItsType($source));
                $this->addContent($form);
            })
            ->on(Form::ON_SUBMIT, function () use ($sourceId): never {
                Database::get()->transaction(fn(Connection $db) => (new SourceRepository($db))->delete($sourceId));
                Notification::success($this->translate('Deleted source successfully'));
                $this->switchToSingleColumnLayout();
            })
            ->on(Form::ON_SENT, function (DeleteSourceForm $form) {
                // TODO: I feel this should be part of CompatForm or CompatController (e.g. $this->sendForm())
                if (! $this->getResponse()->isRedirect()) {
                    $this->addPart($form, $this->content->getAttribute('id')->getValue());
                }
            })
            ->handleRequest($this->getServerRequest());
    }
}
