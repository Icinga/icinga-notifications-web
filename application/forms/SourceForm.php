<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Validator\CallbackValidator;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;
use ipl\Web\Widget\ButtonLink;

class SourceForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var string|int The used password hash algorithm */
    public const HASH_ALGORITHM = PASSWORD_BCRYPT;

    /** @var string @var The generic source type */
    public const TYPE_GENERIC = 'generic';

    /** @var string The type for sources with an integration */
    private const TYPE_INTEGRATED = 'integrated';

    /** @var Connection */
    private Connection $db;

    /** @var ?int */
    private ?int $sourceId = null;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    protected function assemble(): void
    {
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
                'required'  => true
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
                'validators' => [new CallbackValidator(
                    function ($value, CallbackValidator $validator) {
                        // Username must be unique
                        $source = Source::on($this->db)
                            ->filter(Filter::equal('listener_username', $value));
                        if ($this->sourceId !== null) {
                            $source->filter(Filter::unequal('id', $this->sourceId));
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

        $credentials->addElement(
            'password',
            'listener_password',
            [
                'required'      => $this->sourceId === null,
                'label'         => $this->sourceId !== null
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
                'required'      => $this->sourceId === null,
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
                'label' => $this->sourceId === null ?
                    $this->translate('Add Source') :
                    $this->translate('Save Changes')
            ]
        );

        if ($this->sourceId !== null) {
            $this->getElement('save')->prependWrapper(
                (new HtmlDocument())
                    ->addHtml(
                        (new ButtonLink(
                            $this->translate('Delete'),
                            Url::fromPath('notifications/source/delete/', ['id' => $this->sourceId])
                        ))->openInModal()
                    )
            );
        }
    }

    /**
     * Load the source with given id
     *
     * @param int $id
     *
     * @return $this
     */
    public function loadSource(int $id): static
    {
        $this->sourceId = $id;

        $values = $this->fetchDbValues();

        if ($values['type'] === self::TYPE_GENERIC) {
            unset($values['type']);
            $values['source_type'] = self::TYPE_GENERIC;
        } else {
            $values['source_type'] = self::TYPE_INTEGRATED;
        }

        $this->populate($values);

        return $this;
    }

    /**
     * Add the new source
     */
    public function addSource(): void
    {
        $data = $this->getValues();

        $source = [
            'name' => $data['name'],
            'type' => $this->getValue('type', self::TYPE_GENERIC),
            'listener_username' => $data['credentials']['listener_username'],
            // Not using PASSWORD_DEFAULT, as the used algorithm should
            // be kept in sync with what the daemon understands
            'listener_password_hash' => password_hash(
                $data['credentials']['listener_password'],
                self::HASH_ALGORITHM
            ),
            'changed_at' => (int) (new DateTime())->format("Uv")
        ];

        $this->db->transaction(function (Connection $db) use ($source): void {
            $db->insert('source', $source);
        });
    }

    /**
     * Edit the source
     *
     * @return void
     */
    public function editSource(): void
    {
        $data = $this->getValues();

        $source = [
            'name' => $data['name'],
            'type' => $this->getValue('type', self::TYPE_GENERIC),
            'listener_username' => $data['credentials']['listener_username']
        ];

        /** @var ?string $listenerPassword */
        $listenerPassword = $data['credentials']['listener_password'] ?? null;

        if ($listenerPassword) {
            // Not using PASSWORD_DEFAULT, as the used algorithm should
            // be kept in sync with what the daemon understands
            $source['listener_password_hash'] = password_hash($listenerPassword, self::HASH_ALGORITHM);
        } elseif (empty(array_diff_assoc($source, $this->fetchDbValues()))) {
            return;
        }

        $source['changed_at'] = (int) (new DateTime())->format("Uv");
        $this->db->update('source', $source, ['id = ?' => $this->sourceId]);
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
            'name' => $source->name,
            'type' => $source->type,
            'credentials' => [
                'listener_username' => $source->listener_username
            ]
        ];
    }
}
