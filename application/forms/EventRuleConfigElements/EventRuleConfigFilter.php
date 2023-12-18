<?php

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\FormElement\TextElement;
use ipl\Html\HtmlElement;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

class EventRuleConfigFilter extends FieldsetElement
{
    /** @var Url Url of the search editor */
    protected $searchEditorUrl;

    /** @var ?string Event rule's object filter */
    protected $objectFilter;

    protected $defaultAttributes = ['class' => 'config-filter'];

    public function __construct(Url $searchEditorUrl, ?string $filter)
    {
        $this->searchEditorUrl = $searchEditorUrl;
        $this->objectFilter = $filter;

        parent::__construct('config-filter');
    }

    protected function assemble(): void
    {
        if (! $this->getObjectFilter()) {
            $addFilterButton = new SubmitButtonElement(
                'add-filter',
                [
                    'class'          => ['add-button', 'control-button', 'spinner'],
                    'label'          => new Icon('plus'),
                    'formnovalidate' => true,
                    'title'          => $this->translate('Add filter')
                ]
            );
            $this->registerElement($addFilterButton);

            if ($addFilterButton->hasBeenPressed()) {
                $this->removeAttribute('class', 'empty-filter');
            } else {
                $this->addAttributes(['class' => 'empty-filter']);
                $this->addHtml($addFilterButton);

                return;
            }
        }

        $editorOpener = new Link(
            new Icon('cog'),
            $this->searchEditorUrl,
            Attributes::create([
                'class'               => ['search-editor-opener', 'control-button'],
                'title'               => $this->translate('Adjust Filter'),
                'data-icinga-modal'   => true,
                'data-no-icinga-ajax' => true,
            ])
        );

        $searchBar = new TextElement(
            'searchbar',
            [
                'class'    => ['filter-input', 'control-button'],
                'readonly' => true,
                'value'    => $this->getObjectFilter()
            ]
        );

        $filterElement = new HtmlElement(
            'div',
            Attributes::create(['class' => ['search-controls', 'icinga-controls']])
        );

        $filterElement->addHtml($searchBar, $editorOpener);

        $this->addHtml($filterElement);
    }

    /**
     * Get the event rule's object filter
     *
     * @return ?string
     */
    public function getObjectFilter(): ?string
    {
        return $this->objectFilter;
    }
}
