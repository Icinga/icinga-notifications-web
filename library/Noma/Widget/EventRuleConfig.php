<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget;

use Icinga\Module\Noma\Common\Database;
use Icinga\Module\Noma\Forms\AddEscalationForm;
use Icinga\Module\Noma\Forms\AddFilterForm;
use Icinga\Module\Noma\Forms\EscalationConditionForm;
use Icinga\Module\Noma\Forms\EscalationRecipientForm;
use Icinga\Module\Noma\Forms\EventRuleForm;
use Icinga\Module\Noma\Model\ObjectExtraTag;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Form;
use ipl\Html\Html;
use ipl\Orm\Query;
use ipl\Stdlib\Events;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

class EventRuleConfig extends BaseHtmlElement
{
    use Events;

    protected $defaultAttributes = [
        'class' => 'event-rule-detail'
    ];

    public const ON_CHANGE = 'on_change';

    protected $tag = 'div';

    /** @var Form[]  */
    private $forms;

    protected $config;

    /** @var Url The url to open the SearchEditor at */
    protected $searchEditorUrl;

    /** @var array */
    private $escalationForms;

    public function __construct(Url $searchEditorUrl, array $config = null)
    {
        $this->config = $config ?? [];
        $this->searchEditorUrl = $searchEditorUrl;

        $this->createForms();
    }

    protected function createForms(): void
    {
        $config = $this->getConfig();
        $addFilter = (new AddFilterForm())
            ->on(Form::ON_SENT, function () {
                $this->config['showSearchbar'] = true;

                $this->emit(self::ON_CHANGE, [$this]);
            });

        $searchBar = $this->createSearchBar();

        $eventRuleForm = (new EventRuleForm())
            ->populate($config)
            ->on(Form::ON_SENT, function ($form) {
                $this->config['name'] = $form->getValue('name');
                $this->config['is_active'] = $form->getValue('is_active');

                $this->emit(self::ON_CHANGE, [$this]);
            });

        $escalations = $config['rule_escalation'] ?? [1 => []];
        $addEscalation = (new AddEscalationForm())
            ->on(AddEscalationForm::ON_SENT, function (AddEscalationForm $form) use ($escalations) {
                $newPosition = (int) array_key_last($escalations) + 1;
                $this->config['rule_escalation'][$newPosition] = [];
                $this->escalationForms[$newPosition] = [
                    $this->createConditionForm($newPosition),
                    $this->createRecipientForm($newPosition)
                ];

                $this->emit(self::ON_CHANGE, [$this]);
            });

        $this->forms = [
            $eventRuleForm,
            $addFilter,
            $searchBar,
            $addEscalation
        ];

        $this->escalationForms = [];
        foreach ($escalations as $position => $escalation) {
            $values = explode('|', $escalation['condition'] ?? '');
            $escalationCondition = $this->createConditionForm($position, $values);

            $values = $escalation['recipient'] ?? [];
            $escalationRecipient = $this->createRecipientForm($position, $values);

            $this->escalationForms[$position] = [
                $escalationCondition,
                $escalationRecipient
            ];

            $this->forms[] = $escalationCondition;
            $this->forms[] = $escalationRecipient;
        }
    }

    public function createSearchBar(): SearchBar
    {
        $query = ObjectExtraTag::on(Database::get());

        $searchBar = new SearchBar();

        $searchBar->addWrapper(Html::tag('div', ['class' => 'search-controls']));

        $searchBar->setSuggestionUrl(Url::fromPath(
            "noma/event-rule/complete",
            [
                '_disableLayout' => true,
                'showCompact' => true
            ]
        ));

        $searchBar->setEditorUrl($this->searchEditorUrl);

        $query->columns(['tag'])->assembleSelect()->distinct();

        $columnValidator = function (SearchBar\ValidatedColumn $column) use ($query) {
            $searchPath = $column->getSearchValue();

            if ($query->filter(Filter::equal('tag', $searchPath))->count() === 0) {
                $column->setMessage(t('Is not a valid column'));
                $column->setSearchValue($searchPath);
            }
        };

        $searchBar->on(SearchBar::ON_ADD, $columnValidator)
            ->on(SearchBar::ON_INSERT, $columnValidator)
            ->on(SearchBar::ON_SAVE, $columnValidator);

        $searchBar
            ->setFilter(QueryString::parse($this->config['object_filter'] ?? ''))
            ->on(Form::ON_SENT, function ($form) {
                $this->config['object_filter'] = QueryString::render($form->getFilter());

                $this->emit(self::ON_CHANGE, [$this]);
            });

        return $searchBar;
    }

    /**
     * Create and return the SearchEditor
     *
     * @param Query $query The query being filtered
     * @param Url $redirectUrl Url to redirect to upon success
     * @param array $preserveParams Query params to preserve when redirecting
     *
     * @return SearchEditor
     */
    public static function createSearchEditor(Query $query): SearchEditor
    {
        $editor = new SearchEditor();

        $editor->setAction(Url::fromRequest()->getAbsoluteUrl());

        $editor->setSuggestionUrl(Url::fromPath(
            "noma/event-rule/complete",
            ['_disableLayout' => true, 'showCompact' => true, 'id' => Url::fromRequest()->getParams()->get('id')]
        ));

        $editor->on(SearchEditor::ON_VALIDATE_COLUMN, function (
            Filter\Condition $condition
        ) use (
            $query
        ) {
            $searchPath = $condition->getColumn();

            if ($query->filter(Filter::equal('tag', $searchPath))->count() === 0) {
                $condition->setColumn($searchPath);
                throw new SearchBar\SearchException(t('Is not a valid column'));
            }
        });

        return $editor;
    }

    public function getForms(): array
    {
        return $this->forms;
    }

    protected function assemble()
    {
        [$eventRuleForm, $addFilter, $searchBar, $addEscalation] = $this->forms;

        $this->add([
            $eventRuleForm,
            new RightArrow(),
            ($this->config['showSearchbar'] ?? false) ? $searchBar : $addFilter,
            new RightArrow()
        ]);

        $escalations = new Escalations();

        foreach ($this->escalationForms as $position => $escalation) {
            $escalations->addEscalation($position, $escalation);
        }

        $this->add($escalations);
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig($config): self
    {
        $this->config = $config;

        return $this;
    }

    public function isValid(): bool
    {
        foreach ($this->escalationForms as $escalation) {
            [$conditionForm, $recipientForm] = $escalation;

            if (! $conditionForm->isValid() || ! $recipientForm->isValid()) {
                return false;
            }
        }

        return true;
    }

    private function createConditionForm(int $position, array $values = []): EscalationConditionForm
    {
        $cnt = empty(array_filter($values)) ? null : count($values);
        $form = (new EscalationConditionForm($cnt))
            ->addAttributes(['name' => 'escalation-condition-form-' . $position])
            ->on(Form::ON_SENT, function ($form) use ($position) {
                $this->config['rule_escalation'][$position]['condition'] = $form->getValues();

                $this->emit(self::ON_CHANGE, [$this]);
            });

        if ($cnt !== null) {
            $form->populate($values);
        }

        return $form;
    }

    private function createRecipientForm(int $position, array $values = []): EscalationRecipientForm
    {
        $cnt = empty(array_filter($values)) ? null : count($values);
        $form = (new EscalationRecipientForm($cnt))
            ->addAttributes(['name' => 'escalation-recipient-form-' . $position])
            ->on(Form::ON_SENT, function ($form) use ($position) {
                $this->config['rule_escalation'][$position]['recipient'] = $form->getValues();

                $this->emit(self::ON_CHANGE, [$this]);
            });

        if ($cnt !== null) {
            $form->populate($values);
        }

        return $form;
    }
}
