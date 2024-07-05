<?php

/* Icinga Notifications Web | (c) 2023 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\ItemList;

use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Widget\RuleEscalationRecipientBadge;
use ipl\Html\BaseHtmlElement;
use ipl\Html\Html;
use ipl\Stdlib\Filter;
use ipl\Web\Common\BaseListItem;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/**
 * Event-rule item of an event-rule list. Represents one database row.
 */
class EventRuleListItem extends BaseListItem
{
    /** @var Rule The associated list item */
    protected $item;

    /** @var EventRuleList The list where the item is part of */
    protected $list;

    protected function init(): void
    {
        $this->getAttributes()
            ->set('data-action-item', true);
    }

    protected function assembleFooter(BaseHtmlElement $footer): void
    {
        $meta = Html::tag('span', ['class' => 'meta']);

        if ($this->item->object_filter) {
            $meta->add(Html::tag('span', new Icon('filter')));
        }

        $escalationCount = $this->item->rule_escalation->filter(Filter::equal('deleted', 'n'))->count();
        if ($escalationCount > 1) {
            $meta->add(Html::tag('span', [new Icon('code-branch'), $escalationCount]));
        }

        $footer->add($meta);
    }

    protected function assembleTitle(BaseHtmlElement $title): void
    {
        $title->addHtml(new Link($this->item->name, Links::eventRule($this->item->id), ['class' => 'subject']));
    }

    protected function assembleHeader(BaseHtmlElement $header): void
    {
        $header->add($this->createTitle());
        //TODO(sd): need fixes?
        $rs = $this->item->rule_escalation->filter(Filter::equal('deleted', 'n'))->first();
        if ($rs) {
            $recipients = $rs->rule_escalation_recipient->filter(Filter::equal('deleted', 'n'));
            $recipientCount = $recipients->count();
            if ($recipientCount) {
                $header->add(new RuleEscalationRecipientBadge(
                    $recipients->first(),
                    $recipientCount - 1
                ));
            }
        }
    }

    protected function assembleMain(BaseHtmlElement $main): void
    {
        $main->add($this->createHeader());
        $main->add($this->createFooter());
    }
}
