<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\Source;
use Icinga\Web\Session;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\CheckboxElement;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Validator\X509CertValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class SourceForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string|int The used password hash algorithm */
    public const HASH_ALGORITHM = PASSWORD_BCRYPT;

    /** @var Connection */
    private $db;

    /** @var ?int */
    private $sourceId;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    protected function assemble(): void
    {
        $this->addAttributes(['class' => 'source-form']);
        $this->addElement($this->createCsrfCounterMeasure(Session::getSession()->getId()));

        $this->addElement(
            'text',
            'name',
            [
                'label'         => $this->translate('Name'),
                'required'      => true
            ]
        );
        $this->addElement(
            'select',
            'icinga_or_other',
            [
                'label'     => $this->translate('Type'),
                'class'     => 'autosubmit',
                'ignore'    => true,
                'required'  => true,
                'value'     => 'icinga',
                'options'   => [
                    'icinga' => 'Icinga',
                    'other' => $this->translate('Other')
                ]
            ]
        );

        $chosenType = $this->getPopulatedValue('icinga_or_other');
        $configuredType = $this->getPopulatedValue('type');
        $showIcingaApiConfig = $chosenType === 'icinga'
            || ($chosenType === null && $configuredType === null)
            || ($chosenType === null && $configuredType === Source::ICINGA_TYPE_NAME);

        if ($showIcingaApiConfig) {
            // TODO: Shouldn't be necessary: https://github.com/Icinga/ipl-html/issues/131
            $this->clearPopulatedValue('type');

            $this->addElement(
                'hidden',
                'type',
                [
                    'required'  => true,
                    'disabled'  => true,
                    'value'     => Source::ICINGA_TYPE_NAME
                ]
            );
            $this->addElement(
                'text',
                'icinga2_base_url',
                [
                    'required'  => true,
                    'label'     => $this->translate('API URL')
                ]
            );
            $this->addElement(
                'text',
                'icinga2_auth_user',
                [
                    'required'      => true,
                    'label'         => $this->translate('API Username'),
                    'autocomplete'  => 'off'
                ]
            );
            $this->addElement(
                'password',
                'icinga2_auth_pass',
                [
                    'required'      => true,
                    'label'         => $this->translate('API Password'),
                    'autocomplete'  => 'new-password'
                ]
            );
            $this->addElement(
                'checkbox',
                'icinga2_insecure_tls',
                [
                    'class'             => 'autosubmit',
                    'label'             => $this->translate('Verify API Certificate'),
                    'checkedValue'      => 'n',
                    'uncheckedValue'    => 'y',
                    'value'             => true
                ]
            );

            /** @var CheckboxElement $insecureBox */
            $insecureBox = $this->getElement('icinga2_insecure_tls');
            if ($insecureBox->isChecked()) {
                $this->addElement(
                    'text',
                    'icinga2_common_name',
                    [
                        'label'         => $this->translate('Common Name'),
                        'description'   => $this->translate(
                            'The CN of the API certificate. Only required if it differs from the FQDN of the API URL'
                        )
                    ]
                );

                $this->addElement(
                    'textarea',
                    'icinga2_ca_pem',
                    [
                        'cols'          => 64,
                        'rows'          => 28,
                        'required'      => true,
                        'validators'    => [new X509CertValidator()],
                        'label'         => $this->translate('CA Certificate'),
                        'description'   => $this->translate('The certificate of the Icinga CA')
                    ]
                );
            }
        } else {
            $this->getElement('icinga_or_other')->setValue('other');

            $this->addElement(
                'text',
                'type',
                [
                    'required'      => true,
                    'label'         => $this->translate('Type Name'),
                    'validators'    => [new CallbackValidator(function (string $value, CallbackValidator $validator) {
                        if ($value === Source::ICINGA_TYPE_NAME) {
                            $validator->addMessage($this->translate('This name is reserved and cannot be used'));

                            return false;
                        }

                        return true;
                    })]
                ]
            );
            $this->addElement(
                'password',
                'listener_password',
                [
                    'ignore'        => true,
                    'required'      => $this->sourceId === null,
                    'label'         => $this->sourceId !== null
                        ? $this->translate('New Password')
                        : $this->translate('Password'),
                    'autocomplete'  => 'new-password',
                    'validators'    => [['name' => 'StringLength', 'options' => ['min' => 16]]]
                ]
            );
            $this->addElement(
                'password',
                'listener_password_dupe',
                [
                    'ignore'        => true,
                    'required'      => $this->sourceId === null,
                    'label'         => $this->translate('Repeat Password'),
                    'autocomplete'  => 'new-password',
                    'validators'    => [new CallbackValidator(function (string $value, CallbackValidator $validator) {
                        if ($value !== $this->getValue('listener_password')) {
                            $validator->addMessage($this->translate('Passwords do not match'));

                            return false;
                        }

                        return true;
                    })]
                ]
            );

            // Preserves (some) entered data even if the user switches between types
            $this->addElement('hidden', 'icinga2_base_url');
            $this->addElement('hidden', 'icinga2_auth_user');
            $this->addElement('hidden', 'icinga2_insecure_tls');
            $this->addElement('hidden', 'icinga2_common_name');
            $this->addElement('hidden', 'icinga2_ca_pem');
        }

        $this->addElement(
            'submit',
            'save',
            [
                'label' => $this->sourceId === null ?
                    $this->translate('Add Source') :
                    $this->translate('Save Changes')
            ]
        );

        if ($this->sourceId !== null) {
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

            /** @var BaseHtmlElement $submitWrapper */
            $submitWrapper = $this->getElement('save')->getWrapper();
            $submitWrapper->prepend($deleteButton);
        }
    }

    public function isValid()
    {
        $pressedButton = $this->getPressedSubmitElement();
        if ($pressedButton && $pressedButton->getName() === 'delete') {
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
     * Load the source with given id
     *
     * @param int $id
     *
     * @return $this
     */
    public function loadSource(int $id): self
    {
        $this->sourceId = $id;

        $this->populate($this->fetchDbValues());

        return $this;
    }

    /**
     * Add the new source
     */
    public function addSource(): void
    {
        $source = $this->getValues();

        // Not using PASSWORD_DEFAULT, as the used algorithm should
        // be kept in sync with what the daemon understands
        $source['listener_password_hash'] = password_hash(
            $this->getValue('listener_password'),
            self::HASH_ALGORITHM
        );

        $this->db->insert('source', $source);
    }

    /**
     * Edit the source
     *
     * @return void
     */
    public function editSource(): void
    {
        $this->db->beginTransaction();

        $source = $this->getValues();

        if (empty(array_diff_assoc($source, $this->fetchDbValues()))) {
            return;
        }

        /** @var ?string $listenerPassword */
        $listenerPassword = $this->getValue('listener_password');
        if ($listenerPassword) {
            // Not using PASSWORD_DEFAULT, as the used algorithm should
            // be kept in sync with what the daemon understands
            $source['listener_password_hash'] = password_hash($listenerPassword, self::HASH_ALGORITHM);
        }

        $source['changed_at'] = time() * 1000;
        $this->db->update('source', $source, ['id = ?' => $this->sourceId]);

        $this->db->commitTransaction();
    }

    /**
     * Remove the source
     */
    public function removeSource(): void
    {
        $this->db->update(
            'source',
            ['changed_at' => time() * 1000, 'deleted' => 'y'],
            ['id = ?' => $this->sourceId]
        );
    }

    /**
     * Get the source name
     *
     * @return string
     */
    public function getSourceName(): string
    {
        return $this->getValue('name');
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
        /** @var ?Source $source */
        $source = Source::on($this->db)
            ->filter(Filter::equal('id', $this->sourceId))
            ->first();

        if ($source === null) {
            throw new HttpNotFoundException($this->translate('Source not found'));
        }

        return [
            'name'                  => $source->name,
            'type'                  => $source->type,
            'icinga2_base_url'      => $source->icinga2_base_url,
            'icinga2_auth_user'     => $source->icinga2_auth_user,
            'icinga2_auth_pass'     => $source->icinga2_auth_pass,
            'icinga2_ca_pem'        => $source->icinga2_ca_pem,
            'icinga2_common_name'   => $source->icinga2_common_name,
            'icinga2_insecure_tls'  => $source->icinga2_insecure_tls
        ];
    }
}
