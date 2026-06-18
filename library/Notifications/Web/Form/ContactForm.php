<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Web\Form;

use Icinga\Module\Notifications\Form\ConfigProviderInterface;
use Icinga\Module\Notifications\Form\Data\Contact as ContactData;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormDecoration\DescriptionDecorator;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Validator\CallbackValidator;
use ipl\Validator\EmailAddressValidator;
use ipl\Validator\StringLengthValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class ContactForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string Emitted in case the contact should be deleted */
    public const ON_REMOVE = 'on_remove';

    private ConfigProviderInterface $configProvider;

    /**
     * Set the contact to populate the form with
     *
     * @param Contact $contact
     *
     * @return $this
     */
    public function setContact(Contact $contact): static
    {
        $this->populate($this->contactToFormData($contact));

        return $this;
    }

    /**
     * Get the contact as it's currently configured
     *
     * @return ContactData
     */
    public function getContact(): ContactData
    {
        $id = $this->getElement('contact')->getValue('id');
        if ($id !== null) {
            $id = (int) $id;
        }

        return new ContactData(
            $id,
            $this->getElement('contact')->getValue('full_name', ''),
            $this->getElement('contact')->getValue('username') ?: null,
            (int) $this->getElement('contact')->getValue('default_channel_id'),
            array_filter($this->getElement('contact_address')?->getValues() ?? [])
        );
    }

    public function __construct(ConfigProviderInterface $configProvider)
    {
        $this->configProvider = $configProvider;

        $this->applyDefaultElementDecorators();

        $this->on(self::ON_SENT, function () {
            if ($this->hasBeenRemoved()) {
                $this->emit(self::ON_REMOVE, [$this]);
            }
        });
    }

    /**
     * Get whether the user pushed the remove button
     *
     * @return bool
     */
    public function hasBeenRemoved(): bool
    {
        $btn = $this->getPressedSubmitElement();
        $csrf = $this->getElement('CSRFToken');

        return $csrf !== null && $csrf->isValid() && $btn !== null && $btn->getName() === 'delete';
    }

    public function isValidEvent($event): bool
    {
        if ($event === self::ON_REMOVE) {
            return true;
        }

        return parent::isValidEvent($event);
    }

    protected function assemble(): void
    {
        $this->addAttributes(Attributes::create(['class' => 'contact-form']));
        $this->addCsrfCounterMeasure(Session::getSession()->getId());

        // Fieldset for contact full name and username
        $this->addElement('fieldset', 'contact', ['label' => $this->translate('Contact')]);
        $contact = $this->getElement('contact');

        $contact->addElement('hidden', 'id');
        $contactId = $contact->getPopulatedValue('id') ?: null;
        if ($contactId !== null) {
            $contactId = (int) $contactId;
        }

        $contact->addElement(
            'text',
            'full_name',
            [
                'label' => $this->translate('Contact Name'),
                'required' => true
            ]
        );

        // TODO: remove this once https://github.com/Icinga/ipl-html/issues/178 is fixed
        $contact->addElementLoader('ipl\\Web\\FormElement', 'Element');

        $contact->addElement(
            'suggestion',
            'username',
            [
                'label' => $this->translate('Icinga Web User'),
                'description' => $this->translate(
                    'Use this to associate actions in the UI, such as incident management, with this contact.'
                    . ' To successfully receive desktop notifications, this is also required.'
                ),
                'suggestionsUrl' => Url::fromPath(
                    'notifications/contact/suggest-icinga-web-user',
                    ['showCompact' => true, '_disableLayout' => 1]
                ),
                'validators' => [
                    new StringLengthValidator(['max' => 254]),
                    new CallbackValidator(function ($value, $validator) use ($contactId) {
                        $contact = $this->configProvider->findContactByUsername($value);
                        // contact.username is unique so it's safe to exclude the current one just now
                        if ($contact !== null && $contact->id !== $contactId) {
                            $validator->addMessage($this->translate(
                                'A contact with the same username already exists.'
                            ));

                            return false;
                        }

                        return true;
                    })
                ]
            ]
        );
        $contact
            ->getElement('username')
            ->getDecorators()
            ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);

        $availableTypes = [];
        foreach ($this->configProvider->fetchAvailableChannelTypes() as $channelType) {
            $availableTypes[$channelType->type] = $channelType->name;
        }

        $channelNames = [];
        $channelTypes = [];
        foreach ($this->configProvider->fetchChannels() as $channel) {
            $channelNames[$availableTypes[$channel->type]][$channel->id] = $channel->name;
            $channelTypes[$channel->id] = $channel->type;
        }

        $defaultChannel = $this->createElement(
            'select',
            'default_channel_id',
            [
                'label'             => $this->translate('Default Channel'),
                'description'       => $this->translate(
                    "Contact will be notified via the default channel, when no specific channel is configured"
                    . " in an event rule."
                ),
                'required'          => true,
                'class'             => 'autosubmit',
                'disabledOptions'   => [''],
                'options'           => [
                    '' => sprintf(' - %s - ', $this->translate('Please choose'))
                ] + $channelNames,
            ]
        );

        $defaultChannel
            ->getDecorators()
            ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);
        $this->decorate($defaultChannel);

        $contact->registerElement($defaultChannel);

        $this->addAddressElements($availableTypes, $channelTypes[$defaultChannel->getValue() ?? ''] ?? null);

        $this->addHtml(new HtmlElement('hr'));

        $this->addHtml($defaultChannel);

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $contactId === null ?
                    $this->translate('Create Contact') :
                    $this->translate('Save Changes')
            ]
        );
        if ($contactId !== null) {
            /** @var FormSubmitElement $deleteButton */
            $deleteButton = $this->createElement(
                'submit',
                'delete',
                [
                    'label'          => $this->translate('Delete Contact'),
                    'class'          => 'btn-remove',
                    'formnovalidate' => true
                ]
            );

            $this->registerElement($deleteButton);
            $this->getElement('submit')->prependWrapper((new HtmlDocument())->addHtml($deleteButton));
        }
    }

    /**
     * Transform the current contact into form data
     *
     * @param Contact $contact
     *
     * @return array
     */
    private function contactToFormData(Contact $contact): array
    {
        $values['contact'] = [
            'id' => $contact->id,
            'full_name' => $contact->full_name,
            'username' => $contact->username,
            'default_channel_id' => (string) $contact->default_channel_id
        ];

        $values['contact_address'] = [];
        foreach ($contact->contact_address as $contactInfo) {
            $values['contact_address'][$contactInfo->type] = $contactInfo->address;
        }

        return $values;
    }

    /**
     * Add address elements for all existing channel plugins
     *
     * @param array<string, string> $availableChannelTypes The available channel types as `type` => `name` pair
     * @param ?string $defaultType The selected default channel type
     *
     * @return void
     */
    private function addAddressElements(array $availableChannelTypes, ?string $defaultType): void
    {
        if (empty($availableChannelTypes)) {
            return;
        }

        $address = $this->createElement('fieldset', 'contact_address', ['label' => $this->translate('Channels')]);
        $this->addElement($address);

        $address->addHtml(new HtmlElement(
            'p',
            new Attributes(['class' => 'description']),
            new Text($this->translate('Configure the channels available for this contact here.'))
        ));

        foreach ($availableChannelTypes as $type => $name) {
            $element = $this->createElement('text', $type, [
                'label'      => $name,
                'validators' => [new StringLengthValidator(['max' => 255])],
                'required'   => $type === $defaultType && $type !== 'webhook'
            ]);

            if ($type === 'email') {
                $element->addAttributes(['validators' => [new EmailAddressValidator()]]);
            }

            $address->addElement($element);
        }
    }

    protected function onError()
    {
        // TODO: I feel like this should be the case in ipl-html already
        $this->emit(Form::ON_SENT, [$this]);
    }
}
