<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\ConfigurationError;
use Icinga\Module\Notifications\Form\Data\Channel as ChannelData;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\Form;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\BaseFormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\I18n\GettextTranslator;
use ipl\I18n\StaticTranslator;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use ipl\Validator\EmailAddressValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

/**
 * @phpstan-type ChannelOptionConfig array{
 *  name: string,
 *  type: string,
 *  label: array<string, string>,
 *  help?: array<string, string>,
 *  required?: bool,
 *  options?: array<string, string>,
 *  default?: string|bool|int|float,
 *  min?: float|int,
 *  max?: float|int
 *  }
 */
class ChannelForm extends CompatForm
{
    use CsrfCounterMeasure;

    private Connection $db;

    /** @var array<string, mixed> */
    private array $defaultChannelOptions = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;

        $this->applyDefaultElementDecorators();
    }

    /**
     * Set the channel to populate the form with
     *
     * @param Channel $channel
     *
     * @return $this
     */
    public function setChannel(Channel $channel): static
    {
        $this->populate($this->channelToFormData($channel));

        return $this;
    }

    /**
     * Get the channel as it's currently configured
     *
     * @return ChannelData
     */
    public function getChannel(): ChannelData
    {
        $id = $this->getValue('id') ?: null;
        if ($id !== null) {
            $id = (int) $id;
        }

        /** @var array<string, mixed> $config */
        $config = $this->hasElement('config') ? $this->getElement('config')->getValue() : [];

        return new ChannelData(
            $id,
            $this->getValue('name'),
            $this->getValue('type'),
            $this->filterConfig($config)
        );
    }

    /**
     * @throws ConfigurationError
     */
    protected function assemble(): void
    {
        $query = AvailableChannelType::on($this->db)
            ->columns(['type', 'name', 'config_attrs'])
            ->execute();

        if (! $query->hasResult()) {
            throw new ConfigurationError('No channel types available. Make sure Icinga Notifications is running.');
        }

        $this->addAttributes(['class' => 'channel-form']);
        $this->addCsrfCounterMeasure(Session::getSession()->getId());

        $this->addElement('hidden', 'id');
        $channelId = $this->getPopulatedValue('id') ?: null;
        if ($channelId !== null) {
            $channelId = (int) $channelId;
        }

        $this->addElement(
            'text',
            'name',
            [
                'label'         => $this->translate('Name'),
                'autocomplete'  => 'off',
                'required'      => true
            ]
        );

        /** @var string[] $typesConfig */
        $typesConfig = [];

        /** @var string[] $typeNamePair */
        $typeNamePair = [];

        $defaultType = null;
        /** @var Channel $channel */
        foreach ($query as $channel) {
            if ($defaultType === null) {
                $defaultType = $channel->type;
            }

            $typesConfig[$channel->type] = $channel->config_attrs;
            $typeNamePair[$channel->type] = $channel->name;
        }

        $this->addElement(
            'select',
            'type',
            [
                'label'             => $this->translate('Type'),
                'class'             => 'autosubmit',
                'required'          => true,
                'disabledOptions'   => [''],
                'value'             => $defaultType,
                'options'           => $typeNamePair
            ]
        );

        /** @var string $selectedType */
        $selectedType = $this->getValue('type');
        $this->createConfigElements($selectedType, $typesConfig[$selectedType]);

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $channelId === null ?
                    $this->translate('Add Channel') :
                    $this->translate('Save Changes')
            ]
        );

        if ($channelId !== null) {
            $isInUse = Contact::on($this->db)
                ->columns([new Expression('1')])
                ->filter(Filter::equal('default_channel_id', $channelId))
                ->first();

            if ($isInUse === null) {
                $isInUse = RuleEscalationRecipient::on($this->db)
                    ->columns([new Expression('1')])
                    ->filter(Filter::equal('channel_id', $channelId))
                    ->first();
            }

            /** @var FormSubmitElement $deleteButton */
            $deleteButton = $this->createElement(
                'submit',
                'delete',
                [
                    'label'          => $this->translate('Delete'),
                    'class'          => 'btn-remove',
                    'formnovalidate' => true,
                    'disabled'       => $isInUse !== null,
                    'title'          => $isInUse
                        ? $this->translate(
                            "Channel is still referenced as a contact's default"
                            . " channel or in an event rule's escalation"
                        )
                        : null
                ]
            );

            $this->registerElement($deleteButton);
            $this->getElement('submit')
                ->prependWrapper((new HtmlDocument())->addHtml($deleteButton));
        }
    }

    public function isValid(): bool
    {
        if ($this->getPressedSubmitElement()->getName() === 'delete') {
            $csrfElement = $this->getElement('CSRFToken');

            if (! $csrfElement->isValid()) {
                return false;
            }

            return true;
        }

        return parent::isValid();
    }

    public function hasBeenSubmitted(): bool
    {
        if ($this->getPressedSubmitElement() !== null && $this->getPressedSubmitElement()->getName() === 'delete') {
            return true;
        }

        return parent::hasBeenSubmitted();
    }

    /**
     * Create config elements for the given channel type
     *
     * @param string $type The channel type
     * @param string $config The channel type config
     */
    protected function createConfigElements(string $type, string $config): void
    {
        /** @var array<int, ChannelOptionConfig>  $elementsConfig */
        $elementsConfig = json_decode($config, true);

        if (empty($elementsConfig)) {
            $this->prependHtml(
                HtmlElement::create(
                    'ul',
                    Attributes::create(['class' => 'errors']),
                    HtmlElement::create(
                        'li',
                        null,
                        sprintf(
                            $this->translate(
                                'Could not decode options for type \'%s\'.'
                                . ' Check if your database\'s character set is correctly configured.'
                            ),
                            $type
                        )
                    )
                )
            );

            return;
        }

        $configFieldset = new FieldsetElement('config');
        $this->addElement($configFieldset);

        foreach ($elementsConfig as $elementConfig) {
            /** @var BaseFormElement $elem */
            $elem = $this->createElement(
                $this->getElementType($elementConfig['type']),
                $elementConfig['name'],
                $this->getElementOptions($elementConfig)
            );

            if ($type === "email" && $elem->getName() === "sender_mail") {
                $elem->getValidators()->add(new EmailAddressValidator());
            }

            $configFieldset->addElement($elem);
        }
    }

    /**
     * Get the element type for the given option type
     *
     * @param string $configType The option type
     *
     * @return string
     */
    protected function getElementType(string $configType): string
    {
        return match ($configType) {
            'number'            => 'number',
            'text'              => 'textarea',
            'bool'              => 'checkbox',
            'option', 'options' => 'select',
            'secret'            => 'password',
            default             => 'text'
        };
    }

    /**
     * Get the element options from the given element config
     *
     * @param ChannelOptionConfig $elementConfig
     *
     * @return array<string, mixed>
     */
    protected function getElementOptions(array $elementConfig): array
    {
        $options = [
            'label' => $this->fromCurrentLocale($elementConfig['label'])
        ];

        if ($elementConfig['type'] === 'bool') {
            $options['checkedValue'] = 'checked';
            $options['uncheckedValue'] = 'unchecked';
        }

        if (isset($elementConfig['help'])) {
            $options['description'] = $this->fromCurrentLocale($elementConfig['help']);
        }

        if (isset($elementConfig['required'])) {
            $options['required'] = $elementConfig['required'];
        }

        $isSelectElement = isset($elementConfig['options'])
            && ($elementConfig['type'] === 'option' || $elementConfig['type'] === 'options');
        if ($isSelectElement) {
            $options['options'] = $elementConfig['options'];
            if ($elementConfig['type'] === 'options') {
                $options['multiple'] = true;
            }
        }

        if (isset($elementConfig['default'])) {
            $this->defaultChannelOptions[$elementConfig['name']] = $elementConfig['default'];
            $options['value'] = $elementConfig['default'];
        }

        if ($elementConfig['type'] === "number") {
            if (isset($elementConfig['min'])) {
                $options['min'] = $elementConfig['min'];
            }

            if (isset($elementConfig['max'])) {
                $options['max'] = $elementConfig['max'];
            }
        }

        return $options;
    }

    /**
     * Get the current locale based string from given locale map
     *
     * Fallback to locale `en_US` if the current locale isn't provided
     *
     * @param array<string, string> $localeMap
     *
     * @return ?string Only returns null if the fallback locale is also not specified
     */
    protected function fromCurrentLocale(array $localeMap): ?string
    {
        /** @var GettextTranslator $translator */
        $translator = StaticTranslator::$instance;
        $default = $translator->getDefaultLocale();
        $locale = $translator->getLocale();

        return $localeMap[$locale] ?? $localeMap[$default] ?? null;
    }

    /**
     * Filter the config array
     *
     * @param array $config
     *
     * @return ChannelOptionConfig
     */
    private function filterConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if (in_array($value, ['checked', 'unchecked'], true)) {
                $config[$key] = $value === 'checked';
            }

            if (isset($this->defaultChannelOptions[$key])) {
                if ($value === null) {
                    $config[$key] = '';
                } elseif ($this->defaultChannelOptions[$key] === $value) {
                    unset($config[$key]);
                }
            } elseif ($value === null) {
                unset($config[$key]);
            }
        }

        return $config;
    }

    /**
     * Transform the given channel into form data
     *
     * @param Channel $channel
     *
     * @return array<string, mixed>
     */
    private function channelToFormData(Channel $channel): array
    {
        return [
            'id'        => $channel->id,
            'name'      => $channel->name,
            'type'      => $channel->type,
            'config'    => json_decode($channel->config ?? '', true) ?? []
        ];
    }

    /**
     * Validate all elements
     *
     * @return $this
     */
    public function validate(): static
    {
        parent::validate();

        if (! $this->hasElement('config')) {
            $this->isValid = false;
        }

        return $this;
    }

    protected function onError()
    {
        // TODO: I feel like this should be the case in ipl-html already
        $this->emit(Form::ON_SENT, [$this]);
    }
}
