<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Web\Session;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\I18n\StaticTranslator;
use ipl\Sql\Connection;
use ipl\Validator\EmailAddressValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use stdClass;

class ChannelForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var Connection */
    private $db;

    /** @var ?int Channel ID */
    private $channelId;

    /** @var string The current locale */
    private $locale;

    public function __construct(Connection $db, ?int $channelId = null)
    {
        $this->db = $db;
        $this->channelId = $channelId;
        $this->locale = StaticTranslator::$instance->getLocale();
    }

    protected function assemble()
    {
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        $this->addElement(
            'text',
            'name',
            [
                'label'         => $this->translate('Name'),
                'autocomplete'  => 'off',
                'required'      => true
            ]
        );

        $query = AvailableChannelType::on($this->db)->columns(['type', 'name', 'config_attrs']);

        $typesConfig = [];
        $typeNamePair = [];
        $defaultType = null;
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

        $selectedType = $this->getValue('type');
        $this->createConfigElements($selectedType, $typesConfig[$selectedType]);

        $this->addElement(
            'submit',
            'submit',
            [
                'label' => $this->channelId === null ?
                    $this->translate('Add Channel') :
                    $this->translate('Save Changes')
            ]
        );

        if ($this->channelId !== null) {
            /** @var FormSubmitElement $deleteButton */
            $deleteButton = $this->createElement(
                'submit',
                'delete',
                [
                    'label'          => $this->translate('Delete'),
                    'class'          => 'btn-remove',
                    'formnovalidate' => true
                ]
            );

            $this->registerElement($deleteButton);
            $this->getElement('submit')
                ->getWrapper()
                ->prepend($deleteButton);
        }
    }

    public function isValid()
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

    public function hasBeenSubmitted()
    {
        if ($this->getPressedSubmitElement() !== null && $this->getPressedSubmitElement()->getName() === 'delete') {
            return true;
        }

        return parent::hasBeenSubmitted();
    }

    public function populate($values)
    {
        if ($values instanceof Channel) {
            $values = [
                'name'      => $values->name,
                'type'      => $values->type,
                'config'    => json_decode($values->config, true) ?? []
            ];
        }

        parent::populate($values);

        return $this;
    }

    protected function onSuccess()
    {
        if ($this->getPressedSubmitElement()->getName() === 'delete') {
            $this->db->delete('channel', ['id = ?' => $this->channelId]);

            return;
        }

        $channel = $this->getValues();
        $channel['config'] = json_encode($channel['config']);
        if ($this->channelId === null) {
            $this->db->insert('channel', $channel);
        } else {
            $this->db->update('channel', $channel, ['id = ?' => $this->channelId]);
        }
    }

    /**
     * Create config elements for the given channel type
     *
     * @param string $type The channel type
     * @param string $config The channel type config
     */
    protected function createConfigElements(string $type, string $config): void
    {
        $elementsConfig = json_decode($config, false);
        if (empty($elementsConfig)) {
            return;
        }

        $configFieldset = new FieldsetElement('config');
        $this->addElement($configFieldset);

        foreach ($elementsConfig as $elementConfig) {
            $elem = $this->createElement(
                $this->getElementType($elementConfig),
                $elementConfig->name,
                $this->getElementOptions($elementConfig)
            );

            if ($type === "email" && $elem->getName() === "sender_mail") {
                $elem->getValidators()->add(new EmailAddressValidator());
            }

            $configFieldset->addElement($elem);
        }
    }

    /**
     * Get the element type from given element config
     *
     * @param stdClass $elementConfig The config object of an element
     *
     * @return string
     */
    protected function getElementType(stdClass $elementConfig): string
    {
        switch ($elementConfig->type) {
            case 'string':
                $elementType = 'text';
                break;
            case 'number':
                $elementType = 'number';
                break;
            case 'text':
                $elementType = 'textarea';
                break;
            case 'bool':
                $elementType = 'checkbox';
                break;
            case 'option':
            case 'options':
                $elementType = 'select';
                break;
            case 'secret':
                $elementType = 'password';
                break;
            default:
                $elementType = 'text';
        }

        return $elementType;
    }

    /**
     * Get the element options from the given element config
     *
     * @param stdClass $elementConfig
     *
     * @return array
     */
    protected function getElementOptions(stdClass $elementConfig): array
    {
        $options = [
            'label' => $this->fromCurrentLocale($elementConfig->label)
        ];

        if (isset($elementConfig->help)) {
            $options['description'] = $this->fromCurrentLocale($elementConfig->help);
        }

        if (isset($elementConfig->required)) {
            $options['required'] = $elementConfig->required;
        }

        if (isset($elementConfig->placeholder)) {
            $options['placeholder'] = $elementConfig->placeholder;
        }

        if (isset($elementConfig->default)) {
            $options['value'] = $elementConfig->default;
        }

        if (
            isset($elementConfig->options)
            && ($elementConfig->type === 'option' || $elementConfig->type === 'options'))
        {
            $options['options'] = (array) $elementConfig->options;
            if ($elementConfig->type === 'options') {
                $options['multiple'] = true;
            }
        }

        if ($elementConfig->type === "number") {
            if (isset($elementConfig->min)) {
                $options['min'] = $elementConfig->min;
            }

            if (isset($elementConfig->max)) {
                $options['max'] = $elementConfig->max;
            }
        }

        return $options;
    }

    /**
     * Get the current locale based string from given locale map
     *
     * Fallback to locale `en_US` if the current locale isn't provided
     *
     * @param stdClass $localeMap
     *
     * @return ?string Only returns null if the fallback locale is also not specified
     */
    protected function fromCurrentLocale(stdClass $localeMap): ?string
    {
        $default = "en_US";
        $locale = $this->locale;

        return $localeMap->$locale ?? $localeMap->$default ?? null;
    }
}
