<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Controllers;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Forms\SourceForm;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Web\Notification;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Stdlib\Filter;
use ipl\Web\Compat\CompatController;

class SourceController extends CompatController
{
    public function init()
    {
        $this->assertPermission('config/modules');
    }

    public function indexAction(): void
    {
        $sourceId = (int) $this->params->getRequired('id');

        /** @var ?Source $source */
        $source = Source::on(Database::get())
            ->filter(Filter::all(
                Filter::equal('id', $sourceId),
                Filter::equal('deleted', 'n')
            ))
            ->first();
        if ($source === null) {
            $this->httpNotFound($this->translate('Source not found'));
        }

        $form = (new SourceForm(Database::get(), $sourceId))
            ->populate($source)
            ->on(SourceForm::ON_SUCCESS, function (SourceForm $form) {
                /** @var string $sourceName */
                $sourceName = $form->getValue('name');

                /** @var FormSubmitElement $pressedButton */
                $pressedButton = $form->getPressedSubmitElement();
                if ($pressedButton->getName() === 'delete') {
                    Notification::success(sprintf(
                        $this->translate('Deleted source "%s" successfully'),
                        $sourceName
                    ));
                } else {
                    Notification::success(sprintf(
                        $this->translate('Updated source "%s" successfully'),
                        $sourceName
                    ));
                }

                $this->switchToSingleColumnLayout();
            })->handleRequest($this->getServerRequest());

        $this->addTitleTab(sprintf($this->translate('Source: %s'), $source->name));
        $this->addContent($form);
    }
}
