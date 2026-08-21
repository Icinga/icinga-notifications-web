<?php

// SPDX-FileCopyrightText: 2023 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Forms;

use ipl\Html\FormDecoration\DescriptionDecorator;
use ipl\I18n\Translation;
use ipl\Web\Common\CsrfCounterMeasure;
use ipl\Web\Compat\CompatForm;

class EventRuleForm extends CompatForm
{
    use CsrfCounterMeasure;
    use Translation;

    /** @var array<string, string> */
    protected array $sourceTypes = [];

    /** @var bool Whether this form is for a new rule */
    protected bool $isNew = false;

    /**
     * Set the source types to choose from
     *
     * @param array<string, string> $sourceTypes
     *
     * @return $this
     */
    public function setAvailableSourceTypes(array $sourceTypes): static
    {
        $this->sourceTypes = $sourceTypes;

        return $this;
    }

    /**
     * Set whether this form is for a new rule
     *
     * @return $this
     */
    public function setIsNew(): static
    {
        $this->isNew = true;

        return $this;
    }

    protected function assemble(): void
    {
        $this->applyDefaultElementDecorators();
        $this->addCsrfCounterMeasure();

        $this->addElement(
            'text',
            'name',
            [
                'label'     => $this->translate('Title'),
                'required'  => true
            ]
        );

        $this->addElement('select', 'source_type', [
            'label' => $this->translate('Source Type'),
            'required' => true,
            'options' => ['' => ' - ' . $this->translate('Please choose') . ' - '] + $this->sourceTypes,
            'disabledOptions' => [''],
            'value' => ''
        ]);
        if (! $this->isNew) {
            $this->getElement('source_type')
                ->setDescription($this->translate(
                    'Choosing a different source type will reset all filters of the rule'
                ))
                ->getDecorators()
                ->replaceDecorator('Description', DescriptionDecorator::class, ['class' => 'description']);
        }

        $this->addElement('submit', 'btn_submit', [
            'label' => $this->translate('Save')
        ]);
    }
}
