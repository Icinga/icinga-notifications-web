<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Widget\Icon;

class CheckboxIcon extends BaseHtmlElement
{
    /** @var bool Whether the checkbox icon is checked */
    protected $isChecked;

    protected $tag = 'span';

    protected $defaultAttributes = ['class' => 'checkbox-icon'];

    /**
     * Create the checkbox icon
     *
     * @param bool $isChecked
     */
    public function __construct(bool $isChecked = false)
    {
        $this->isChecked = $isChecked;
    }

    protected function assemble()
    {
        $this->add(Html::tag('span', ['class' => 'inner-slider']));

        if ($this->isChecked) {
            $this->addAttributes(['class' => 'checked']);
        }
    }
}
