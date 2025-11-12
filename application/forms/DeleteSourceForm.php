<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Forms;

use DateTime;
use Icinga\Exception\Http\HttpNotFoundException;
use Icinga\Module\Notifications\Common\Database;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\Source;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class DeleteSourceForm extends CompatForm
{
    use CsrfCounterMeasure;

    /** @var ?Source The source to delete */
    protected ?Source $source = null;

    /**
     * Load the source with the given ID from the database
     *
     * @param int $id
     *
     * @return $this
     *
     * @throws HttpNotFoundException
     */
    public function loadSource(int $id): static
    {
        $source = Source::on(Database::get())
            ->columns(['id', 'name'])
            ->filter(Filter::equal('id', $id))
            ->first();
        /** @var ?Source $source */
        if ($source === null) {
            throw new HttpNotFoundException($this->translate('Source not found'));
        }

        $this->source = $source;

        return $this;
    }

    /**
     * Get this source's name
     *
     * @return string
     */
    public function getSourceName(): string
    {
        return $this->source->name;
    }

    protected function assemble(): void
    {
        $this->applyDefaultElementDecorators();
        $this->addCsrfCounterMeasure();

        $this->addHtml(new HtmlElement(
            'p',
            null,
            Text::create($this->translate('Are you sure you want to delete this source?'))
        ));

        $this->addHtml(new HtmlElement(
            'ul',
            null,
            new HtmlElement(
                'li',
                null,
                Text::create($this->translate(
                    'Deleting a source also removes all related event rules and stops event processing for it.'
                ))
            ),
            new HtmlElement(
                'li',
                null,
                Text::create($this->translate(
                    'No new incidents will be opened or closed, and no further notifications will be sent.'
                ))
            )
        ));

        $this->addElement('submit', 'delete', [
            'label' => $this->translate('Understood. Delete this source.'),
            'class' => 'btn-remove'
        ]);
    }

    /**
     * Delete the source and all associated event rules from the database
     *
     * @param Connection $db
     *
     * @return void
     */
    public function removeSource(Connection $db): void
    {
        $rules = Rule::on($db)
            ->columns('id')
            ->filter(Filter::equal('source_id', $this->source->id));
        foreach ($rules as $rule) {
            EventRuleConfigForm::removeRule($db, $rule);
        }

        $db->update(
            'source',
            ['changed_at' => (int) (new DateTime())->format("Uv"), 'deleted' => 'y', 'listener_username' => null],
            ['id = ?' => $this->source->id]
        );
    }
}
