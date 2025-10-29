<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\HtmlDocument;
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

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Name'),
                'required'  => true
            ]
        );
        $this->addElement(
            'text',
            'type',
            [
                'required'  => true,
                'label'     => $this->translate('Type Name')
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
        $source = $this->getValues();

        // Not using PASSWORD_DEFAULT, as the used algorithm should
        // be kept in sync with what the daemon understands
        $source['listener_password_hash'] = password_hash(
            $this->getValue('listener_password'),
            self::HASH_ALGORITHM
        );

        $source['changed_at'] = (int) (new DateTime())->format("Uv");

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
            'type' => $source->type
        ];
    }
}
