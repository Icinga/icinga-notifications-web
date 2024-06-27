<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\AvailableChannelType;
use Icinga\Module\Notifications\Model\Channel;
use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use Icinga\Web\Session;
use ipl\Html\Attributes;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\BaseFormElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\HtmlElement;
use ipl\I18n\GettextTranslator;
use ipl\I18n\StaticTranslator;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Validator\EmailAddressValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use Ramsey\Uuid\Uuid;

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

    /** @var Connection */
    private $db;

    /** @var ?int Channel ID */
    private $channelId;

    /** @var array<string, mixed> */
    private $defaultChannelOptions = [];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    protected function assemble()
    {
        $this->addAttributes(['class' => 'channel-form']);
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
                'label' => $this->channelId === null ?
                    $this->translate('Add Channel') :
                    $this->translate('Save Changes')
            ]
        );

        if ($this->channelId !== null) {
            $isInUse = Contact::on($this->db)
                ->columns('1')
                ->filter(Filter::equal('default_channel_id', $this->channelId))
                ->first();

            if ($isInUse === null) {
                $isInUse = RuleEscalationRecipient::on($this->db)
                    ->columns('1')
                    ->filter(Filter::equal('channel_id', $this->channelId))
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

    /**
     * Load the channel with given id
     *
     * @param int $id
     *
     * @return $this
     *
     * @throws HttpNotFoundException
     */
    public function loadChannel(int $id): self
    {
        $this->channelId = $id;
        $this->populate($this->fetchDbValues());

        return $this;
    }

    /**
     * Add the new channel
     */
    public function addChannel(): void
    {
        $channel = $this->getValues();
        $channel['config'] = json_encode($this->filterConfig($channel['config']), JSON_FORCE_OBJECT);
        $channel['changed_at'] = (int) (new DateTime())->format("Uv");
        $channel['external_uuid'] = Uuid::uuid4()->toString();

        $this->db->transaction(function (Connection $db) use ($channel): void {
            $db->insert('channel', $channel);
        });
    }

    /**
     * Edit the channel
     *
     * @return void
     */
    public function editChannel(): void
    {
        $this->db->beginTransaction();

        $channel = $this->getValues();
        $storedValues = $this->fetchDbValues();

        $channel['config'] = json_encode($this->filterConfig($channel['config']), JSON_FORCE_OBJECT);
        $storedValues['config'] = json_encode($storedValues['config'], JSON_FORCE_OBJECT);

        if (! empty(array_diff_assoc($channel, $storedValues))) {
            $channel['changed_at'] = (int) (new DateTime())->format("Uv");

            $this->db->update('channel', $channel, ['id = ?' => $this->channelId]);
        }

        $this->db->commitTransaction();
    }

    /**
     * Remove the channel
     */
    public function removeChannel(): void
    {
        $this->db->transaction(function (Connection $db): void {
            $db->update(
                'channel',
                ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y'],
                ['id = ?' => $this->channelId]
            );
        });
    }

    /**
     * Get the channel name
     *
     * @return string
     */
    public function getChannelName(): string
    {
        return $this->getValue('name');
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
        switch ($configType) {
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
     * Fetch the values from the database
     *
     * @return array
     *
     * @throws HttpNotFoundException
     */
    private function fetchDbValues(): array
    {
        /** @var Channel $channel */
        $channel = Channel::on($this->db)
            ->filter(Filter::equal('id', $this->channelId))
            ->first();

        if ($channel === null) {
            throw new HttpNotFoundException($this->translate('Channel not found'));
        }

        return [
            'name'      => $channel->name,
            'type'      => $channel->type,
            'config'    => json_decode($channel->config, true) ?? []
        ];
    }

    /**
     * Validate all elements
     *
     * @return $this
     */
    public function validate(): self
    {
        parent::validate();

        if (! $this->hasElement('config')) {
            $this->isValid = false;
        }

        return $this;
    }
}
