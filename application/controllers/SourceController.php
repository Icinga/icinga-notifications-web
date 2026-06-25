<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\DeleteSourceForm;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Module\Notifications\Model\Source;
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
    private Source $source;

    public function init(): void
    {
        $this->assertPermission('config/modules');

        $source = (new SourceRepository(Database::get()))
            ->find((int) $this->params->getRequired('id'));

        if ($source === null) {
            $this->httpNotFound($this->translate('Source not found'));
        }

        $this->source = $source;
    }

    public function indexAction(): void
    {
        $form = (new SourceForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setSource($this->source)
            ->on(Form::ON_SUBMIT, function (SourceForm $form): never {
                $source = $form->getSource();

                if ($source->locked) {
                    throw new RuntimeException('Source is locked');
                }

                Database::get()->transaction(fn (Connection $db) => (new SourceRepository($db))->update($source));
                Notification::success(sprintf(
                    $this->translate('Updated source "%s" successfully'),
                    $source->name
                ));

                $this->switchToSingleColumnLayout();
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf($this->translate('Source: %s'), $this->source->name));
        $this->addContent($form);
    }

    public function deleteAction(): void
    {
        if ($this->source->locked) {
            throw new RuntimeException('Source is locked');
        }

        $form = (new DeleteSourceForm())
            ->setCsrfCounterMeasureId(Session::getSession()->getId())
            ->setAction(Url::fromRequest()->getAbsoluteUrl())
            ->on(Form::ON_SUBMIT, function (): never {
                Database::get()->transaction(fn (Connection $db) => (new SourceRepository($db))->delete($this->source));
                Notification::success($this->translate('Deleted source successfully'));
                $this->switchToSingleColumnLayout();
            })
            ->handleRequest($this->getServerRequest());

        $this->setTitle(sprintf($this->translate('Delete Source: %s'), $this->source->name));
        $this->addContent($form);
    }
}
