<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget;

use Icinga\Module\Notifications\Common\Icons;
use Icinga\Module\Notifications\Common\Links;
use Icinga\Module\Notifications\Model\Rule;
use Icinga\Module\Notifications\Model\RuleEntry;
use Icinga\Module\Notifications\Model\RuleEntryRecipient;
use Icinga\Module\Notifications\Widget\EventRule\EscalationCondition;
use Icinga\Module\Notifications\Widget\EventRule\EventTypes;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Orm\Query;
use ipl\Web\Compat\StyleWithNonce;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use JsonException;

class EventRule extends BaseHtmlElement
{
    use Translation;

    private const ESCALATION_RULE = 'escalation';

    private const NOTIFICATION_RULE = 'notification';

    protected $tag = 'div';

    protected $defaultAttributes = [
        'class' => 'event-rule',
    ];

    /**
     * Create a new event rule detail
     *
     * @param Rule $rule The event rule to render
     */
    public function __construct(
        private readonly Rule $rule,
    ) {
    }

    protected function assemble()
    {
        $this->addHtml(
            new HtmlElement(
                'div',
                Attributes::create(['class' => ['title', 'object-filter-title']]),
                Text::create($this->translate('Object Filters'))
            ),
            new HtmlElement(
                'div',
                Attributes::create(['class' => ['title', 'condition-title']]),
                Text::create(match ($this->rule->type) {
                    self::ESCALATION_RULE => $this->translate('Escalation Conditions'),
                    self::NOTIFICATION_RULE => $this->translate('Event Types'),
                })
            ),
            new HtmlElement(
                'div',
                Attributes::create(['class' => ['title', 'recipients-title']]),
                Text::create($this->translate('Recipients'))
            )
        );

        $this->addHtml(
            new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
            new HtmlElement(
                'div',
                Attributes::create(['id' => 'object-filter-controls']),
                $this->createObjectFilterControls($this->rule->object_filter)
            ),
            new HtmlElement('div', Attributes::create(['class' => 'connector-line']))
        );

        /** @var RuleEntry[] $entries */
        $entries = iterator_to_array($this->rule->rule_entry->execute());

        $firstEntry = null;
        if (! empty($entries) && (int) $entries[0]->position === 0) {
            $firstEntry = array_shift($entries);
        }

        $listStyle = new StyleWithNonce();

        $firstEntryButton = (new Link(
            $firstEntry === null
                ? [new Icon('plus'), match ($this->rule->type) {
                    self::ESCALATION_RULE => $this->translate('Add Escalation'),
                    self::NOTIFICATION_RULE => $this->translate('Add Recipients'),
                }]
                : [new Icon('edit'), match ($this->rule->type) {
                    self::ESCALATION_RULE => $this->translate('Edit Escalation'),
                    self::NOTIFICATION_RULE => $this->translate('Edit Recipients'),
                }],
            $firstEntry === null
                ? match ($this->rule->type) {
                    self::ESCALATION_RULE => Links::escalationAdd($this->rule->id, 0),
                    self::NOTIFICATION_RULE => Links::notificationRecipientsAdd($this->rule->id, 0),
                }
                : match ($this->rule->type) {
                    self::ESCALATION_RULE => Links::escalationEdit($firstEntry->id),
                    self::NOTIFICATION_RULE => Links::notificationRecipientsEdit($firstEntry->id),
                },
            Attributes::create([
                'class' => 'button-link'
            ])
        ))->openInModal();
        $listStyle->addFor($firstEntryButton, ['grid-row' => '~"1 / span 1"']);

        $firstEntryItem = new HtmlElement(
            'li',
            null,
            new HtmlElement(
                'div',
                null,
                new HtmlElement('div', Attributes::create(['class' => 'connector-line']))
            ),
            new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
            new HtmlElement(
                'div',
                Attributes::create(['class' => 'outline']),
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => ['description', 'condition']]),
                    $firstEntry?->condition === null
                        ? Text::create(match ($this->rule->type) {
                            self::ESCALATION_RULE => $this->translate('Immediate'),
                            self::NOTIFICATION_RULE => $this->translate('None', 'event.types'),
                        })
                        : EventTypes::fromCommaSeparatedString($this->rule->source_type, $firstEntry->condition)
                ),
                new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => ['description', 'recipients']]),
                    $firstEntry === null
                        ? Text::create(match ($this->rule->type) {
                            self::ESCALATION_RULE => $this->translate(
                                'No recipients will be notified immediately.'
                            ),
                            self::NOTIFICATION_RULE => $this->translate(
                                'No recipients will be notified.'
                            )
                        })
                        : $this->describeRecipients($firstEntry->rule_entry_recipient)
                ),
                $firstEntryButton
            )
        );

        $entryList = new HtmlElement(
            'ol',
            Attributes::create(['class' => 'entries']),
            $firstEntryItem
        );

        if ($this->rule->type === self::ESCALATION_RULE) {
            $nextPosition = 1;
            foreach ($entries as $i => $entry) {
                $entryButton = (new Link(
                    [new Icon('edit'), $this->translate('Edit Escalation')],
                    Links::escalationEdit($entry->id),
                    Attributes::create([
                        'class' => 'button-link'
                    ])
                ))->openInModal();
                $listStyle->addFor($entryButton, ['grid-row' => sprintf('~"%d / span 1"', $i + 2)]);

                $entryList->addHtml(new HtmlElement(
                    'li',
                    null,
                    new HtmlElement( // TODO: Replace this with the drag handle
                        'div',
                        null,
                        new HtmlElement('div', Attributes::create(['class' => 'connector-line']))
                    ),
                    new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
                    new HtmlElement(
                        'div',
                        Attributes::create(['class' => 'outline']),
                        new HtmlElement(
                            'div',
                            Attributes::create(['class' => ['description', 'condition']]),
                            EscalationCondition::fromQueryString($entry->condition)
                        ),
                        new HtmlElement('div', Attributes::create(['class' => 'connector-line'])),
                        new HtmlElement(
                            'div',
                            Attributes::create(['class' => ['description', 'recipients']]),
                            $this->describeRecipients($entry->rule_entry_recipient)
                        ),
                        $entryButton
                    )
                ));

                $nextPosition++;
            }

            $entryList->addHtml(new HtmlElement(
                'li',
                content: (new Link(
                    new Icon('plus'),
                    Links::escalationAdd($this->rule->id, $nextPosition),
                    Attributes::create([
                        'class' => ['button-link', 'add-button'],
                        'title' => $this->translate('Add Escalation')
                    ])
                ))->openInModal()
            ));
        }

        $this->addHtml($entryList);
        $this->addHtml($listStyle);
    }

    /**
     * Create and return the controls to configure the object filter
     *
     * @param ?string $json
     *
     * @return ValidHtml
     *
     * @throws JsonException
     */
    private function createObjectFilterControls(?string $json): ValidHtml
    {
        if ($json !== null) {
            $parsedFilter = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            $icon = 'filter';
            if (! empty($parsedFilter['filter_name'])) {
                $text = $parsedFilter['filter_name'];
                $title = sprintf(
                    '%s (%s: %s)',
                    $this->translate('Adjust Filter'),
                    $this->translate('Name'),
                    $parsedFilter['filter_name']
                );
            } else {
                $text = $this->translate('Adjust Filter');
                $title = $text;
            }
        } else {
            $icon = 'plus';
            $text = $this->translate('Add Filter');
            $title = $text;
        }

        return new HtmlElement(
            'div',
            Attributes::create(['class' => 'button-wrapper']),
            (new Link(
                [
                    new Icon($icon),
                    new HtmlElement('span', content: Text::create($text))
                ],
                Links::eventRuleFilter($this->rule->id),
                Attributes::create([
                    'class' => ['search-editor-opener', 'filter-button'],
                    'title' => $title
                ])
            ))->openInModal()
        );
    }

    /**
     * Return a textual representation for the given entry recipients
     *
     * @param Query<RuleEntryRecipient> $query
     *
     * @return ValidHtml
     */
    private function describeRecipients(Query $query): ValidHtml
    {
        $recipients = $query->withColumns([
            'contact.full_name',
            'contactgroup.name',
            'schedule.name',
            'channel.name',
            'channel_label' => 'channel.available_channel_type.name'
        ]);

        $recipientList = new HtmlElement('ul');
        foreach ($recipients as $recipient) {
            [$icon, $name] = match (true) {
                isset($recipient->contact->full_name) => [
                    new Icon(Icons::USER, ['title' => $this->translate('Contact')]),
                    $recipient->contact->full_name
                ],
                isset($recipient->contactgroup->name) => [
                    new Icon(Icons::CONTACTGROUP, ['title' => $this->translate('Contact group')]),
                    $recipient->contactgroup->name
                ],
                isset($recipient->schedule->name) => [
                    new Icon(Icons::SCHEDULE, ['title' => $this->translate('Schedule')]),
                    $recipient->schedule->name
                ],
            };

            $channelLabel = Text::create(
                $recipient->channel_label ?? $this->translate('Contact Default Channel')
            );

            $recipientList->addHtml(new HtmlElement(
                'li',
                null,
                $icon,
                HtmlElement::create(
                    'span',
                    Attributes::create(['class' => 'recipient-name', 'title' => $name]),
                    Text::create($name)
                ),
                FormattedString::create(
                    '(%s)',
                    $recipient->channel->name
                        ? new HtmlElement(
                            'span',
                            Attributes::create(['title' => $recipient->channel->name]),
                            $channelLabel
                        )
                        : $channelLabel
                )
            ));
        }

        return $recipientList;
    }
}
