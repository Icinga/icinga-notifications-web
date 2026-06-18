<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Web\Form;

use ArrayIterator;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\ContactAddress;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormDecoration\DescriptionDecorator;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Orm\ResultSet;
use ipl\Stdlib\Filter;
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

    private ?Contact $contact = null;

    /**
     * Set the contact to populate the form with
     *
     * @param Contact $contact
     *
     * @return $this
     */
    public function setContact(Contact $contact): static
    {
        $this->contact = $contact;
        $this->populate($this->contactToFormData());

        return $this;
    }

    /**
     * Get the contact as it's currently configured
     *
     * @return Contact
     */
    public function getContact(): Contact
    {
        if ($this->contact === null) {
            $this->contact = new Contact();
        }

        return $this->contact;
    }

    public function __construct()
    {
        $this->applyDefaultElementDecorators();

        $this->on(self::ON_SENT, function () {
            if ($this->hasBeenRemoved()) {
                $this->emit(self::ON_REMOVE, [$this]);
            }
        });
    }

    protected function onSuccess()
    {
        $this->applyChanges();
    }

    /**
     * Get whether the user pushed the remove button
     *
     * @return bool
     */
    private function hasBeenRemoved(): bool
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
                    new CallbackValidator(function ($value, $validator) {
                        $contact = Contact::on(Database::get())
                            ->filter(Filter::equal('username', $value));
                        if ($this->contact !== null) {
                            $contact->filter(Filter::unequal('id', $this->contact->id));
                        }

                        if ($contact->first() !== null) {
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

        $channelQuery = Channel::on(Database::get())
            ->columns(['id', 'name', 'type']);

        $availableTypes = Database::get()->fetchPairs(
            AvailableChannelType::on(Database::get())->columns(['type', 'name'])->assembleSelect()
        );

        $channelNames = [];
        $channelTypes = [];
        foreach ($channelQuery as $channel) {
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
                'label' => $this->contact === null ?
                    $this->translate('Create Contact') :
                    $this->translate('Save Changes')
            ]
        );
        if ($this->contact !== null) {
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
     * @return array
     */
    private function contactToFormData(): array
    {
        $values['contact'] = [
            'full_name'          => $this->contact->full_name,
            'username'           => $this->contact->username,
            'default_channel_id' => (string) $this->contact->default_channel_id
        ];

        $values['contact_address'] = [];
        foreach ($this->contact->contact_address as $contactInfo) {
            $values['contact_address'][$contactInfo->type] = $contactInfo->address;
        }

        return $values;
    }

    /**
     * Apply the user's changes to the contact
     *
     * @return void
     */
    private function applyChanges(): void
    {
        $contact = $this->getContact();

        $contact->full_name = $this->getElement('contact')->getValue('full_name');
        $contact->username = $this->getElement('contact')->getValue('username');
        $contact->default_channel_id = (int) $this->getElement('contact')->getValue('default_channel_id');

        $addresses = [];
        foreach ($this->getElement('contact_address')->getValues() as $type => $address) {
            $addresses[] = new ContactAddress([
                'type' => $type,
                'address' => $address
            ]);
        }

        $contact->contact_address = new ResultSet(new ArrayIterator($addresses));
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
}
