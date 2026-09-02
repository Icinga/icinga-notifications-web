<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Form\Data\Source as SourceData;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
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

    /**
     * Set the source to populate the form with
     *
     * @param Source $source
     *
     * @return $this
     */
    public function setSource(Source $source): static
    {
        $this->populate($this->prepareFormPopulate($source));

        return $this;
    }

    /**
     * Get the source as configured by the user
     *
     * @return SourceData
     */
    public function getSource(): SourceData
    {
        return new SourceData(
            $this->getValue('id'),
            $this->getValue('type'),
            $this->getValue('name'),
            $this->getElement('credentials')->getValue('listener_username') ?: null,
            $this->getElement('credentials')->getValue('listener_password') ?: null,
            $this->getElement('credentials')->getValue('client_certificate_subject') ?: null,
            (bool) $this->getValue('locked')
        );
    }

    public function __construct()
    {
        $this->applyDefaultElementDecorators();
    }

    protected function assemble(): void
    {
        $this->addAttributes(Attributes::create(['class' => 'source-form']));
        $this->addCsrfCounterMeasure();

        $this->addElement('hidden', 'id');
        $sourceId = $this->getPopulatedValue('id') ?: null;
        if ($sourceId !== null) {
            $sourceId = (int) $sourceId;
        }

        $this->addElement('hidden', 'locked');
        $locked = ($this->getPopulatedValue('locked') ?: null) !== null;

        if ($locked) {
            $this->addHtml(new Callout(
                CalloutType::Info,
                $this->translate('This source is managed by an integration, so changes can only be applied through it.')
            ));
        }

        $this->addHtml(new HtmlElement(
            'p',
            Attributes::create(['class' => 'description']),
            Text::create($this->translate(
                'Sources are the most vital part of Icinga Notifications.'
                . ' They submit events that will be processed to notify users about incidents.'
                . ' You configure here how they relate to event rules and their credentials they will present to'
                . ' communicate with the Icinga Notifications API.'
            ))
        ));

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Source Name'),
                'required'  => true,
                'disabled'  => $locked
            ]
        );

        $this->addHtml(
            new HtmlElement(
                'p',
                Attributes::create(['class' => 'description']),
                Text::create(
                    $this->translate(
                        'The source type is used to establish a link to event rules.'
                        . ' Enter the value as stated in the source\'s documentation.'
                        . ' Note that integrated sources usually provide their own configuration interface'
                        . ' for notifications, which is the recommended way to set them up.'
                    )
                )
            )
        );
        $this->addElement(
            'text',
            'type',
            [
                'required'  => true,
                'label'     => $this->translate('Source Type'),
                'disabled'  => $locked
            ]
        );

        $this->addElement(
            'select',
            'auth_type',
            [
                'class' => 'autosubmit',
                'disabled' => $locked,
                'label' => $this->translate('Authentication Type'),
                'value' => 'password',
                'options' =>
                    [
                        'password' => $this->translate('Username and password'),
                        'certificate' => $this->translate('Client certificate')
                    ]
            ]
        );

        $this->addElement('fieldset', 'credentials', [
            'label' => $this->translate('Source Credentials')
        ]);
        $credentials = $this->getElement('credentials');
        if ($this->getPopulatedValue('auth_type', 'password') === 'password') {
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
                    'disabled' => $locked,
                    'validators' => [new CallbackValidator(
                        function ($value, CallbackValidator $validator) use ($sourceId) {
                            // Username must be unique
                            $source = Source::on(Database::get())
                                ->filter(Filter::equal('listener_username', $value));
                            if ($sourceId !== null) {
                                $source->filter(Filter::unequal('id', $sourceId));
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

            if (! $locked) {
                $credentials->addElement(
                    'password',
                    'listener_password',
                    [
                        'required'      => $sourceId === null,
                        'label'         => $sourceId !== null
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
                        'required'      => $sourceId === null,
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
            $credentials->addHtml(new HtmlElement(
                'p',
                Attributes::create(['class' => 'description']),
                Text::create($this->translate(
                    'The source will authenticate using a TLS client certificate when submitting events'
                    . ' over HTTPS. Enter the subject of the certificate the source will present.'
                    . ' Icinga Notifications uses it to identify the source.'
                ))
            ));

            $credentials->addElement(
                'text',
                'client_certificate_subject',
                [
                    'required' => true,
                    'label' => $this->translate('Certificate Subject'),
                    'disabled' => $locked,
                    'placeholder' => 'CN=source.example.com,OU=Monitoring,O=Icinga,C=DE',
                    'validators' => [
                        ['name' => 'StringLength', 'options' => ['max' => 768]],
                        new CallbackValidator(function ($value, CallbackValidator $validator) use ($sourceId) {
                            $source = Source::on(Database::get())
                                ->filter(Filter::equal('client_certificate_subject', $value));
                            if ($sourceId !== null) {
                                $source->filter(Filter::unequal('id', $sourceId));
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

        if (! $locked) {
            $this->addElement(
                'submit',
                'save',
                [
                    'label' => $sourceId === null
                        ? $this->translate('Add Source')
                        : $this->translate('Save Changes')
                ]
            );
        }

        if ($sourceId !== null) {
            $deleteButton = (new ButtonLink(
                $this->translate('Delete'),
                Url::fromPath('notifications/source/delete/', ['id' => $sourceId])
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
     * Fetch the values from the database
     *
     * @param Source $source
     *
     * @return array<string, string>
     */
    private function prepareFormPopulate(Source $source): array
    {
        return [
            'id' => $source->id,
            'locked' => $source->locked ?: null,
            'name' => $source->name,
            'type' => $source->type,
            'auth_type' => $source->listener_username === null ? 'certificate' : 'password',
            'credentials' => [
                'listener_username' => $source->listener_username,
                'client_certificate_subject' => $source->client_certificate_subject
            ]
        ];
    }

    protected function onError()
    {
        // TODO: I feel like this should be the case in ipl-html already
        $this->emit(Form::ON_SENT, [$this]);
    }
}
