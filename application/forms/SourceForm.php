<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Hook\V1\SourceHook;
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
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use Throwable;

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
        $this->applyDefaultElementDecorators();
        $this->addCsrfCounterMeasure();

        $chosenIntegration = null;

        $types = ['' => ' - ' . $this->translate('Please choose') . ' - '];
        foreach (Hook::all('Notifications/v1/Source') as $hook) {
            /** @var SourceHook $hook */
            try {
                $type = $hook->getSourceType();
                if ($this->getPopulatedValue('type') === $type) {
                    $chosenIntegration = $hook;
                }

                $types[$type] = $hook->getSourceLabel();
            } catch (Throwable $e) {
                Logger::error('Failed to load source integration %s: %s', $hook::class, $e);
            }
        }

        $this->addHtml(new HtmlElement(
            'p',
            Attributes::create(['class' => 'description']),
            Text::create($this->translate(
                'Sources are the most vital part of Icinga Notifications. They submit events that will be'
                . ' processed to notify users about incidents. You can only configure sources that provide an'
                . ' integration in Icinga Web. If you cannot choose the desired source below, consult their'
                . ' documentation on how to integrate it.'
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
            'type',
            [
                'required'          => true,
                'label'             => $this->translate('Source Type'),
                'class'             => 'autosubmit',
                'options'           => $types,
                'disabledOptions'   => ['']
            ]
        );

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
                . ' source\'s configuration as well:'
            )),
            Text::create(' '),
            match ($chosenIntegration?->getSourceType()) {
                'icinga2' => new Link(
                    [
                        $this->translate('Icinga DB Documentation'),
                        ' ',
                        new Icon('arrow-up-right-from-square')
                    ],
                    Url::fromPath(
                        'https://icinga.com/docs/icinga-db'
                        . '/latest/doc/03-Configuration/#notifications-configuration'
                    ),
                    ['target' => '_blank']
                ),
                'icinga_for_kubernetes' => new Link(
                    [
                        $this->translate('Icinga for Kubernetes Documentation'),
                        ' ',
                        new Icon('arrow-up-right-from-square')
                    ],
                    Url::fromPath(
                        'https://icinga.com/docs/icinga-for-kubernetes'
                        . '/latest/doc/03-Configuration/#notifications-configuration'
                    ),
                    ['target' => '_blank']
                ),
                default => Text::create($this->translate(
                    'Please choose the source type above to see the required configuration.'
                ))
            }
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
        $data = $this->getValues();

        $source = [
            'name' => $data['name'],
            'type' => $data['type'],
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
            'type' => $data['type'],
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
