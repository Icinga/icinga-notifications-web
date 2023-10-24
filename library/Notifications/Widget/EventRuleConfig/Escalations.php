<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\EventRuleConfig;

use Icinga\Module\Notifications\Forms\EventRuleConfig\RemoveEscalationForm;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;

class Escalations extends BaseHtmlElement
{
    protected $defaultAttributes = ['class' => 'escalations'];

    protected $tag = 'div';

    protected $config;

    private $escalations = [];

    protected function assemble()
    {
        $this->add($this->escalations);
    }

    public function addEscalation(int $position, array $escalation, RemoveEscalationForm $removeEscalationForm)
    {
        $flowLine = (new FlowLine())->getRightArrow();

        if (
            in_array(
                'count-zero-escalation-condition-form',
                $escalation[0]->getAttributes()->get('class')->getValue()
            )
        ) {
            $flowLine->addAttributes(['class' => 'right-arrow-long']);
        }

        $this->escalations[$position] = Html::tag(
            'div',
            ['class' => 'escalation'],
            [
                $removeEscalationForm,
                $flowLine,
                $escalation[0],
                $flowLine,
                $escalation[1],
            ]
        );
    }
}
