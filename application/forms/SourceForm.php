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

    /** @var string The generic source type */
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

        if ($this->source?->locked) {
            $this->addHtml(new Callout(
                CalloutType::Info,
                $this->translate('This source is managed by an integration, so changes can only be applied through it.')
            ));
        }

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

        $this->addElement(
            'select',
            'auth_type',
            [
                'class' => 'autosubmit',
                'disabled' => $this->source?->locked,
                'label' => $this->translate('Authentication Type'),
                'value' => 'password',
                'options' =>
                    [
                        'password' => $this->translate('Username and password'),
                        'certificate' => $this->translate('Client certificate')
                    ]
            ]
        );

        if ($this->getPopulatedValue('auth_type', 'password') === 'password') {
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

            if (! $this->source?->locked) {
                $credentials->addElement(
                    'password',
                    'listener_password',
                    [
                        'required'      => $this->source?->listener_password_hash === null,
                        'label'         => $this->source?->listener_password_hash !== null
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
                        'required'      => $this->source?->listener_password_hash === null,
                        'label'         => $this->translate('Repeat Password'),
                        'autocomplete'  => 'new-password',
                        'validators' => [
                            new CallbackValidator(function (string $value, CallbackValidator $validator) {
                                if ($value !== $this->getElement('credentials')->getValue('listener_password')) {
                                    $validator->addMessage($this->translate('Passwords do not match'));

                                    return false;
                                }

                                return true;
                            })
                        ]
                    ]
                );
            }
        } else {
            $this->addElement('fieldset', 'certificate', [
                'label' => $this->translate('Client Certificate')
            ]);
            $certificate = $this->getElement('certificate');
            $certificate->addHtml(new HtmlElement(
                'p',
                Attributes::create(['class' => 'description']),
                Text::create($this->translate(
                    'The source will authenticate using a TLS client certificate when submitting events'
                    . ' over HTTPS. Enter the subject of the certificate the source will present.'
                    . ' Icinga Notifications uses it to identify the source.'
                ))
            ));

            $certificate->addElement(
                'text',
                'client_certificate_subject',
                [
                    'required' => true,
                    'label' => $this->translate('Certificate Subject'),
                    'disabled' => $this->source?->locked,
                    'placeholder' => 'CN=source.example.com,OU=Monitoring,O=Icinga,C=DE',
                    'validators' => [
                        ['name' => 'StringLength', 'options' => ['max' => 768]],
                        new CallbackValidator(function ($value, CallbackValidator $validator) {
                            $source = Source::on(Database::get())
                                ->filter(Filter::equal('client_certificate_subject', $value));
                            if ($this->source !== null) {
                                $source->filter(Filter::unequal('id', $this->source->id));
                            }

                            if ($source->first() !== null) {
                                $validator->addMessage(
                                    $this->translate('This certificate subject is already in use.')
                                );
                                return false;
                            }

                            return true;
                        })
                    ]
                ]
            );
        }

        if ($this->source === null || ! $this->source->locked) {
            $this->addElement(
                'submit',
                'save',
                [
                    'label' => $this->source === null
                        ? $this->translate('Add Source')
                        : $this->translate('Save Changes')
                ]
            );
        }

        if ($this->source !== null) {
            $deleteButton = (new ButtonLink(
                $this->translate('Delete'),
                Url::fromPath('notifications/source/delete/', ['id' => $this->source->id])
            ))->openInModal();

            if ($this->hasElement('save')) {
                $this->getElement('save')->prependWrapper(
                    (new HtmlDocument())->addHtml($deleteButton)
                );
            } else {
                $this->addHtml(new HtmlElement(
                    'div',
                    new Attributes(['class' => ['control-group', 'form-controls']]),
                    $deleteButton
                ));
            }
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
            'auth_type' => $source->listener_username === null ? 'certificate' : 'password',
            'credentials' => [
                'listener_username' => $source->listener_username
            ],
            'certificate' => [
                'client_certificate_subject' => $source->client_certificate_subject
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
            $this->source = (new Source())->setNew();
        }

        $this->source->name = $this->getValue('name');
        $this->source->type = $this->getValue('type', self::TYPE_GENERIC);

        if ($this->getValue('auth_type') === 'certificate') {
            $this->source->client_certificate_subject = $this->getElement('certificate')
                ->getValue('client_certificate_subject');
            $this->source->listener_username = null;
            $this->source->listener_password_hash = null;
        } else {
            $this->source->listener_username = $this->getElement('credentials')->getValue('listener_username');
            $this->source->client_certificate_subject = null;

            $pwd = $this->getElement('credentials')->getValue('listener_password');
            if ($pwd) {
                $this->source->listener_password = $pwd;
            }
        }

        return $this->source;
    }
}
