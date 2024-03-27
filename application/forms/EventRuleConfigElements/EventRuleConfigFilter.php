<?php

namespace Icinga\Module\Notifications\Forms\EventRuleConfigElements;

use ipl\Html\Attributes;
use ipl\Html\FormElement\FieldsetElement;
use ipl\Html\FormElement\SubmitButtonElement;
use ipl\Html\FormElement\TextElement;
use ipl\Html\Html;
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

    protected function assemble(): void
    {
        $this->addElement(
            'hidden',
            'show-searchbar',
            ['value' => '0']
        );

        /** @var SubmitButtonElement $addFilterButton */
        $addFilterButton = $this->createElement(
            'submitButton',
            'add-filter',
            [
                'class'          => ['add-button', 'control-button', 'spinner'],
                'label'          => new Icon('plus'),
                'formnovalidate' => true,
                'title'          => $this->translate('Add filter')
            ]
        );

        $this->registerElement($addFilterButton);
        /** @var string $showSearchBar */
        $showSearchBar = $this->getValue('show-searchbar');
        if ($this->objectFilter !== '' || $addFilterButton->hasBeenPressed()) {
            $showSearchBar = '1';
            $this->getElement('show-searchbar')->setValue($showSearchBar);
            $this->removeAttribute('class', 'empty-filter');
        }

        if ($showSearchBar === '0') {
            /** @var SubmitButtonElement $filterElement */
            $filterElement = $addFilterButton;
            $this->addAttributes(['class' => 'empty-filter']);
        } else {
            $editorOpener = new Link(
                new Icon('cog'),
                $this->getSearchEditorUrl(),
                Attributes::create([
                    'class'               => 'search-editor-opener control-button',
                    'title'               => t('Adjust Filter'),
                    'data-icinga-modal'   => true,
                    'data-no-icinga-ajax' => true,
                ])
            );

            $searchBar = new TextElement(
                'searchbar',
                [
                    'class'    => 'filter-input control-button',
                    'readonly' => true,
                    'value'    => $this->objectFilter
                ]
            );

            $filterElement = Html::tag('div', ['class' => 'search-controls icinga-controls']);
            $filterElement->add([$searchBar, $editorOpener]);
        }

        $this->add($filterElement);
    }

    /**
     * Set the Url of the search editor
     *
     * @param Url $url
     *
     * @return $this
     */
    public function setSearchEditorUrl(Url $url): self
    {
        $this->searchEditorUrl = $url;

        return $this;
    }

    /**
     * Get the search editor's Url
     *
     * @return Url
     */
    public function getSearchEditorUrl(): Url
    {
        return $this->searchEditorUrl;
    }

    /**
     * Set the event rule's object filter
     *
     * @param string $filter
     *
     * @return $this
     */
    public function setObjectFilter(string $filter): self
    {
        $this->objectFilter = rawurldecode($filter);

        return $this;
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
