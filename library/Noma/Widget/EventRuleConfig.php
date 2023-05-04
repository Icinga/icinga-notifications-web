<?php

/* Icinga NoMa Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Noma\Widget;

use Icinga\Module\Noma\Forms\AddEscalationForm;
use Icinga\Module\Noma\Forms\AddFilterForm;
use Icinga\Module\Noma\Forms\EscalationConditionForm;
use Icinga\Module\Noma\Forms\EscalationRecipientForm;
use ipl\Html\Attributes;
use Icinga\Module\Noma\Forms\RemoveEscalationForm;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Form;
use ipl\Html\FormElement\TextElement;
use ipl\Html\Html;
use ipl\Orm\Query;
use ipl\Stdlib\Events;
use ipl\Stdlib\Filter;
use ipl\Web\Control\SearchBar;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleConfig extends BaseHtmlElement
{
    use Events;

    protected $defaultAttributes = [
        'class' => 'event-rule-detail'
    ];

    public const ON_CHANGE = 'on_change';

    protected $tag = 'div';

    /** @var Form[] */
    private $forms;

    /** @var array The config  */
    protected $config;

    /** @var Url The url to open the SearchEditor at */
    protected $searchEditorUrl;

    /** @var array */
    private $escalationForms = [];

    /** @var RemoveEscalationForm[] */
    private $removeEscalationForms;

    /** @var int */
    private $numEscalations;

    public function __construct(Url $searchEditorUrl, $config = [])
    {
        $this->searchEditorUrl = $searchEditorUrl;
        $this->setConfig($config);

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

        $escalations = $config['rule_escalation'] ?? [1 => []];

        if (! isset($this->config['rule_escalation'])) {
            $this->config['rule_escalation'] = $escalations;
        }

        $addEscalation = (new AddEscalationForm())
            ->on(AddEscalationForm::ON_SENT, function () use ($escalations) {
                $newPosition = (int) array_key_last($escalations) + 1;
                $this->config['rule_escalation'][$newPosition] = [];

                $this->removeEscalationForms[$newPosition] =  $this->createRemoveEscalationForm($newPosition);

                if ($newPosition === 2) {
                    $this->removeEscalationForms[1] =  $this->createRemoveEscalationForm(1);
                    $this->forms[] = $this->removeEscalationForms[1];
                }

                $this->escalationForms[$newPosition] = [
                    $this->createConditionForm($newPosition),
                    $this->createRecipientForm($newPosition)
                ];

                $this->emit(self::ON_CHANGE, [$this]);
            });

        $this->forms = [
            $addFilter,
            $addEscalation
        ];

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

            if (count($escalations) > 1) {
                $removeEscalation = $this->createRemoveEscalationForm($position);

                $this->forms[] = $removeEscalation;
                $this->removeEscalationForms[$position] = $removeEscalation;
            }
        }
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
        [$addFilter, $addEscalation] = $this->forms;

        $addFilterButtonOrSearchBar = $addFilter;
        $horizontalLine = (new FlowLine())->getHorizontalLine();
        if (! empty($this->config['showSearchbar'])) {
            $editorOpener = new Link(
                new Icon('cog'),
                $this->searchEditorUrl,
                Attributes::create([
                    'class'                 => 'search-editor-opener control-button',
                    'title'                 => t('Adjust Filter'),
                    'data-icinga-modal'     => true,
                    'data-no-icinga-ajax'   => true,
                ])
            );

            $searchBar = new TextElement(
                'searchbar',
                [
                    'class'     => 'filter-input control-button',
                    'readonly'  => true,
                    'value'     => $this->config['object_filter'] ?? null
                ]
            );

            $addFilterButtonOrSearchBar = Html::tag('div', ['class' => 'search-controls icinga-controls']);
            $addFilterButtonOrSearchBar->add([$searchBar, $editorOpener]);
        } else {
            $horizontalLine->getAttributes()
                ->add(['class' => 'horizontal-line-long']);
        }

        $this->add([
            (new FlowLine())->getRightArrow(),
            $addFilterButtonOrSearchBar,
            $horizontalLine
        ]);

        $escalations = new Escalations();

        foreach ($this->escalationForms as $position => $escalation) {
            if (isset($this->removeEscalationForms[$position])) {
                $escalations->addEscalation($position, $escalation, $this->removeEscalationForms[$position]);
            } else {
                $escalations->addEscalation($position, $escalation);
            }
        }

        $this->add($escalations);

        $this->add($addEscalation);
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
        } else {
            $form->addAttributes(['class' => 'count-zero-escalation-condition-form']);
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

    private function createRemoveEscalationForm(int $position): RemoveEscalationForm
    {
        return (new RemoveEscalationForm())
            ->addAttributes(['name' => 'remove-escalation-form-' . $position])
            ->on(Form::ON_SENT, function ($form) use ($position) {
                unset($this->config['rule_escalation'][$position]);
                unset($this->escalationForms[$position]);
                unset($this->removeEscalationForms[$position]);

                if (! empty($this->config['rule_escalation'])) {
                    $this->config['rule_escalation'] = array_combine(
                        range(
                            1,
                            count($this->config['rule_escalation'])
                        ),
                        array_values($this->config['rule_escalation'])
                    );
                }

                if (! empty($this->removeEscalationForms)) {
                    $this->removeEscalationForms = array_combine(
                        range(
                            1,
                            count($this->removeEscalationForms)
                        ),
                        array_values($this->removeEscalationForms)
                    );
                }

                if (! empty($this->escalationForms)) {
                    $this->escalationForms = array_combine(
                        range(
                            1,
                            count($this->escalationForms)
                        ),
                        array_values($this->escalationForms)
                    );
                }

                $numEscalation = count($this->escalationForms);

                foreach ($this->removeEscalationForms as $position => $removeEscalationForm) {
                    $this->removeEscalationForms[$position] = $removeEscalationForm
                        ->setAttribute('name', 'remove-escalation-form-' . $position);
                }

                foreach ($this->escalationForms as $position => $escalationForm) {
                    $conditionForm = $escalationForm[0]
                        ->setAttribute('name', 'escalation-condition-form-' . $position);

                    $recipientForm = $escalationForm[1]
                        ->setAttribute('name', 'escalation-recipient-form-' . $position);

                    $this->escalationForms[$position] = [$conditionForm, $recipientForm];
                }

                if ($numEscalation === 1) {
                    unset($this->removeEscalationForms[1]);
                }

                $this->emit(self::ON_CHANGE, [$this]);
            });
    }
}