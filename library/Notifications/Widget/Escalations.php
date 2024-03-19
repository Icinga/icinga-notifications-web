<?php

/*
 * Icinga Notifications Web | (c) 2023-2024 Icinga GmbH | GPLv2
 */

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Forms\RemoveEscalationForm;
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

    public function addEscalation(int $position, array $escalation, ?RemoveEscalationForm $removeEscalationForm = null)
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

        if ($removeEscalationForm) {
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
        } else {
            $this->escalations[$position] = Html::tag(
                'div',
                ['class' => 'escalation'],
                [
                    $flowLine->addAttributes(['class' => 'right-arrow-one-escalation']),
                    $escalation[0],
                    $flowLine,
                    $escalation[1]
                ]
            );
        }
    }
}
