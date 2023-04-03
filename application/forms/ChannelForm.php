<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Forms;

use Icinga\Module\Noma\Model\Channel;
use Icinga\Web\Session;
use ipl\Html\Contract\FormSubmitElement;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Sql\Connection;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class ChannelForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var Connection */
    private $db;

    /** @var ?int Channel ID */
    private $channelId;

    public function __construct(Connection $db, ?int $channelId = null)
    {
        $this->db = $db;
        $this->channelId = $channelId;
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

        $type = [
            ''            => sprintf(' - %s - ', $this->translate('Please choose')),
            'email'       => $this->translate('Email'),
            'rocketchat'  => 'Rocket.Chat'
        ];

        $this->addElement(
            'select',
            'type',
            [
                'label'    => $this->translate('Type'),
                'class'    => 'autosubmit',
                'required' => true,
                'disable'  => [''],
                'options'  => $type
            ]
        );

        $options = new FieldsetElement('config');
        $this->addElement($options);

        $selectedType = $this->getValue('type');

        if ($selectedType === 'email') {
            $options->addElement(
                'text',
                'host',
                [
                    'label'         => $this->translate('SMTP Host'),
                    'autocomplete'  => 'off',
                    'placeholder'   => 'localhost'
                ]
            )->addElement(
                'select',
                'port',
                [
                    'label'   => $this->translate('SMTP Port'),
                    'options' => [
                        25   => 25,
                        465  => 465,
                        587  => 587,
                        2525 => 2525
                    ]
                ]
            )->addElement(
                'text',
                'from',
                [
                    'label'         => $this->translate('From'),
                    'autocomplete'  => 'off',
                    'placeholder'   => 'noma@icinga'
                ]
            )->addElement(
                'password',
                'password',
                [
                    'label'         => $this->translate('Password'),
                    'autocomplete'  => 'off',
                ]
            );

            $options->addElement(
                'checkbox',
                'tls',
                [
                    'label'          => 'TLS / SSL',
                    'class'          => 'autosubmit',
                    'checkedValue'   => '1',
                    'uncheckedValue' => '0',
                    'value'          => '1'
                ]
            );

            if ($options->getElement('tls')->getValue() === '1') {
                $options->addElement(
                    'checkbox',
                    'tls_certcheck',
                    [
                        'label'          => $this->translate('Certificate Check'),
                        'class'          => 'autosubmit',
                        'checkedValue'   => '1',
                        'uncheckedValue' => '0',
                        'value'          => '0'
                    ]
                );
            }
        } elseif ($selectedType === 'rocketchat') {
            $options->addElement(
                'text',
                'url',
                [
                    'label'    => $this->translate('Rocket.Chat URL'),
                    'required' => true
                ]
            )->addElement(
                'text',
                'user_id',
                [
                    'autocomplete'  => 'off',
                    'label'         => $this->translate('User ID'),
                    'required'      => true
                ]
            )->addElement(
                'password',
                'token',
                [
                    'autocomplete'  => 'off',
                    'label'         => $this->translate('Personal Access Token'),
                    'required'      => true
                ]
            );
        }

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
            $values->config = json_decode($values->config);
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
}
