<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CalloutType;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;
use ipl\Web\Widget\Callout;

class SourceForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string @var The generic source type */
    public const TYPE_GENERIC = 'generic';

    /** @var string The type for sources with an integration */
    private const TYPE_INTEGRATED = 'integrated';

    /** @var ?Source The source to load */
    private ?Source $source = null;

    protected function assemble(): void
    {
        $this->addAttributes(Attributes::create(['class' => 'source-form']));
        $this->applyDefaultElementDecorators();
        $this->addCsrfCounterMeasure();
        $this->addHtml(new HtmlElement(
            'p',
            Attributes::create(['class' => 'description']),
            Text::create($this->translate(
                'Sources are the most vital part of Icinga Notifications. They submit events that will be'
                . ' processed to notify users about incidents. You can either configure sources that provide an'
                . ' integration in Icinga Web, or use the generic type for sources that communicate directly with'
                . ' the Icinga Notifications API.'
            ))
        ));

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Source Name'),
                'required'  => true,
                'disabled'  => $this->source?->locked
            ]
        );
        $this->addElement(
            'select',
            'source_type',
            [
                'ignore'    => true,
                'required'  => true,
                'label'     => $this->translate('Source Type'),
                'value'     => self::TYPE_GENERIC,
                'disabled'  => $this->source?->locked,
                'class'     => 'autosubmit',
                'options'   => [
                    self::TYPE_GENERIC    => $this->translate('Generic', 'notifications.source.type'),
                    self::TYPE_INTEGRATED => $this->translate('Integrated', 'notifications.source.type')
                ]
            ]
        );

        if ($this->getPopulatedValue('source_type') === self::TYPE_INTEGRATED) {
            $this->addHtml(
                new HtmlElement(
                    'p',
                    Attributes::create(['class' => 'description']),
                    Text::create(
                        $this->translate(
                            'Enter the source identifier as stated in the integration\'s documentation.'
                            . ' Note that integrated sources usually provide their own configuration interface for'
                            . ' notifications, which is the recommended way to set them up.'
                        )
                    )
                )
            );
            $this->addElement(
                'text',
                'type',
                [
                    'required'      => true,
                    'label'         => $this->translate('Source Identifier'),
                    'disabled'  => $this->source?->locked
                ]
            );
        }

        $this->addElement('fieldset', 'credentials', [
            'label' => $this->translate('Source Credentials')
        ]);
        $credentials = $this->getElement('credentials');
        $credentials->addHtml(new HtmlElement(
            'p',
            Attributes::create(['class' => 'description']),
            Text::create($this->translate(
                'These credentials will be used by the source to authenticate'
                . ' against Icinga Notifications when submitting events. You will need to add this to the'
                . ' source\'s configuration as well.'
                . ' Consult the documentation of your source for configuration details.'
            ))
        ));

        $credentials->addElement(
            'text',
            'listener_username',
            [
                'required' => true,
                'label' => $this->translate('Username'),
                'disabled'  => $this->source?->locked,
                'validators' => [new CallbackValidator(
                    function ($value, CallbackValidator $validator) {
                        // Username must be unique
                        $source = Source::on(Database::get())
                            ->filter(Filter::equal('listener_username', $value));
                        if ($this->source !== null) {
                            $source->filter(Filter::unequal('id', $this->source->id));
                        }

                        if ($source->first() !== null) {
                            $validator->addMessage($this->translate('This username is already in use.'));
                            return false;
                        }

                        return true;
                    }
                )]
            ]
        );

        if ($this->source?->locked) {
            $this->prependHtml(new Callout(
                CalloutType::Info,
                $this->translate('This source is managed by an integration, so changes can only be applied through it.')
            ));

            return;
        }

        $credentials->addElement(
            'password',
            'listener_password',
            [
                'required'      => $this->source === null,
                'label'         => $this->source !== null
                    ? $this->translate('New Password')
                    : $this->translate('Password'),
                'autocomplete'  => 'new-password',
                'validators'    => [['name' => 'StringLength', 'options' => ['min' => 16]]]
            ]
        );
        $credentials->addElement(
            'password',
            'listener_password_dupe',
            [
                'ignore'        => true,
                'required'      => $this->source === null,
                'label'         => $this->translate('Repeat Password'),
                'autocomplete'  => 'new-password',
                'validators'    => [new CallbackValidator(function (string $value, CallbackValidator $validator) {
                    if ($value !== $this->getElement('credentials')->getValue('listener_password')) {
                        $validator->addMessage($this->translate('Passwords do not match'));

                        return false;
                    }

                    return true;
                })]
            ]
        );

        $this->addElement(
            'submit',
            'save',
            [
                'label' => $this->source === null ?
                    $this->translate('Add Source') :
                    $this->translate('Save Changes')
            ]
        );

        if ($this->source !== null) {
            $this->getElement('save')->prependWrapper(
                (new HtmlDocument())
                    ->addHtml(
                        (new ButtonLink(
                            $this->translate('Delete'),
                            Url::fromPath('notifications/source/delete/', ['id' => $this->source->id])
                        ))->openInModal()
                    )
            );
        }
    }

    /**
     * Set the source to populate the form with
     *
     * @param Source $source
     *
     * @return $this
     */
    public function setSource(Source $source): static
    {
        $this->source = $source;

        $this->populate([
            'name' => $source->name,
            'type' => $source->type,
            'source_type' => $source->type === self::TYPE_GENERIC ? self::TYPE_GENERIC : self::TYPE_INTEGRATED,
            'credentials' => [
                'listener_username' => $source->listener_username
            ]
        ]);

        return $this;
    }

    /**
     * Get the source as configured by the user
     *
     * @return Source
     */
    public function getSource(): Source
    {
        if ($this->source === null) {
            $this->source = new Source();
        }

        $this->source->name = $this->getValue('name');
        $this->source->type = $this->getValue('type', self::TYPE_GENERIC);
        $this->source->listener_username = $this->getElement('credentials')->getValue('listener_username');
        $pwd = $this->getElement('credentials')->getValue('listener_password');
        if ($pwd) {
            $this->source->listener_password = $pwd;
        }

        return $this->source;
    }
}
