<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Model\Contact;
use Icinga\Module\Notifications\Model\RuleEscalationRecipient;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Web\Widget\Icon;

class RuleEscalationRecipientBadge extends BaseHtmlElement
{
    /** @var RuleEscalationRecipient */
    protected $recipient;

    /** @var int */
    protected $moreCount;

    protected $tag = 'span';

    protected $defaultAttributes = ['class' => 'rule-escalation-recipient-badge'];

    /**
     * Create the rule escalation recipient badge with icon
     *
     * @param RuleEscalationRecipient $recipient
     * @param ?int $moreCount The more count to show
     */
    public function __construct(RuleEscalationRecipient $recipient, ?int $moreCount = null)
    {
        $this->recipient = $recipient;
        $this->moreCount = $moreCount;
    }

    public function createBadge()
    {
        $recipientModel = $this->recipient->getRecipient();
        $nameColumn = 'name';
        $icon = 'users';

        if ($recipientModel instanceof Contact) {
            $nameColumn = 'full_name';
            $icon = 'user';
        }

        return Html::tag('span', ['class' => 'badge'], [new Icon($icon), $recipientModel->$nameColumn]);
    }

    protected function assemble()
    {
        $this->add($this->createBadge());

        if ($this->moreCount) {
            $this->add(Html::tag('span', sprintf(' + %d more', $this->moreCount)));
        }
    }
}
