<?php

/* Icinga Notifications Web | (c) 2025 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Widget\Timeline;

use DateTime;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\FormattedString;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Web\Widget\Icon;

class EntryFlyout extends BaseHtmlElement
{
    use Translation;

    /** @var ?Member Member who is on duty during the entry's timespan */
    protected ?Member $activeMember = null;

    /** @var ?string Mode of the entry's rotation can be "partial", "multi" or "24-7"*/
    protected ?string $mode = null;

    /** @var ?array All members of the entry's rotation */
    protected ?array $rotationMembers = null;

    /** @var ?array Rotation start time, end time, and frequency */
    protected ?array $rotationOptions = null;

    /** @var ?string First handoff of the rotation */
    protected ?string $firstHandoff = null;

    /** @var ?string Name of the entry's rotation */
    protected ?string $rotationName = null;

    /** @var ?ValidHtml Information about timespan, frequency and the first handoff */
    protected ?ValidHtml $timeInfo = null;

    /** @var ?ValidHtml Information about name and mode of the rotation */
    protected ?ValidHtml $nameInfo = null;

    /**
     * Set active member and return a new instance
     *
     * @param Member $member
     *
     * @return static
     */
    public function withActiveMember(Member $member): static
    {
        if (! isset($this->timeInfo)) {
            $this->generateAndSetRotationInfo();
        }

        $flyout = clone $this;
        $flyout->activeMember = $member;

        return $flyout;
    }

    /**
     * Set the rotation mode of the entry
     *
     * @param string $mode
     *
     * @return $this
     */
    public function setMode(string $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Set rotation members of the entry
     *
     * @param array $members
     *
     * @return $this
     */
    public function setRotationMembers(array $members): static
    {
        $this->rotationMembers = $members;

        return $this;
    }

    /**
     * Set the options of the entry's rotation
     *
     * @param array $options
     *
     * @return $this
     */
    public function setRotationOptions(array $options): static
    {
        $this->rotationOptions = $options;

        return $this;
    }

    /**
     * Set the first handoff date of the entry's rotation
     *
     * @param string $firstHandoff
     *
     * @return $this
     */
    public function setFirstHandoff(string $firstHandoff): static
    {
        $this->firstHandoff = $firstHandoff;

        return $this;
    }

    /**
     * Set the name of the entry's rotation
     *
     * @param string $rotationName
     *
     * @return $this
     */
    public function setRotationName(string $rotationName): static
    {
        $this->rotationName = $rotationName;

        return $this;
    }

    public function assemble(): void
    {
        if (count($this->rotationMembers) > 1) {
            $memberList = new HtmlElement('span', Attributes::create(['class' => 'rotation-info-member-list']));
            $activeMemberIndex = 0;
            foreach ($this->rotationMembers as $i => $member) {
                if (
                    $member->contact_id !== null &&
                    $member->contact->full_name === $this->activeMember->getName()
                ) {
                    $activeMemberIndex = $i;
                    break;
                } elseif (
                    $member->contactgroup_id !== null &&
                    $member->contactgroup->name === $this->activeMember->getName()
                ) {
                    $activeMemberIndex = $i;
                    break;
                }
            }

            $membersOrdered = array_merge(
                array_slice($this->rotationMembers, $activeMemberIndex + 1),
                array_slice($this->rotationMembers, 0, $activeMemberIndex + 1)
            );

            $hiddenMemberCount = count(array_splice($membersOrdered, 2));
            $visibleNames = (new HtmlDocument())->setSeparator(', ');
            foreach ($membersOrdered as $member) {
                if ($member->contact_id !== null) {
                    $visibleNames->addHtml(
                        new HtmlElement(
                            'span',
                            null,
                            new Icon('user'),
                            Text::create($member->contact->full_name)
                        )
                    );
                } else {
                    $visibleNames->addHtml(
                        new HtmlElement(
                            'span',
                            null,
                            new Icon('users'),
                            Text::create($member->contactgroup->name)
                        )
                    );
                }
            }

            $memberList->addHtml($visibleNames);
            if ($hiddenMemberCount > 0) {
                $memberList->addHtml(
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => ['rotation-info-member-count']]),
                        Text::create(sprintf($this->translate(' + %d more'), $hiddenMemberCount))
                    )
                );
            }
        }

        $this->addHtml(
            $this->nameInfo,
            $this->timeInfo
        );

        if (isset($memberList)) {
            $this->addHtml(
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'rotation-info-members']),
                    FormattedString::create(
                        $this->translate('Next in order %s'),
                        $memberList
                    )
                )
            );
        }

        $this->addHtml(
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'rotation-info-on-duty']),
                FormattedString::create(
                    $this->translate('%s is on duty'),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => 'rotation-info-active-member-name']),
                        new Icon($this->activeMember->getIcon()),
                        Text::create($this->activeMember->getName())
                    )
                )
            )
        );
    }

    /**
     * Generate and save the part of the entry flyout, that remains equal for all entries of the rotation
     *
     * @return $this
     */
    protected function generateAndSetRotationInfo(): static
    {
        $this->setTag('div');
        $this->setAttribute('class', 'rotation-info');

        $weekdayNames = [
            1 => $this->translate("Mon"),
            2 => $this->translate("Tue"),
            3 => $this->translate("Wed"),
            4 => $this->translate("Thu"),
            5 => $this->translate("Fri"),
            6 => $this->translate("Sat"),
            7 => $this->translate("Sun")
        ];

        $noneType = \IntlDateFormatter::NONE;
        $shortType = \IntlDateFormatter::SHORT;
        $timeFormatter = new \IntlDateFormatter(\Locale::getDefault(), $noneType, $shortType);
        $dateFormatter = new \IntlDateFormatter(\Locale::getDefault(), $shortType, $noneType);
        $firstHandoff = $dateFormatter->format(DateTime::createFromFormat('Y-m-d', $this->firstHandoff));

        if (($this->rotationOptions['frequency'] ?? null) === 'd') {
            $handoff = sprintf(
                $this->translatePlural(
                    'Handoff every day',
                    'Handoff every %d days',
                    (int) $this->rotationOptions['interval']
                ),
                $this->rotationOptions['interval']
            );
        } else {
            $handoff = sprintf(
                $this->translatePlural(
                    'Handoff every week',
                    'Handoff every %d weeks',
                    (int) $this->rotationOptions['interval']
                ),
                $this->rotationOptions['interval']
            );
        }

        $startTime = match ($this->mode) {
            '24-7'    => $this->rotationOptions['at'],
            'partial' => $this->rotationOptions['from'],
            'multi'   => $this->rotationOptions['from_at'],
        };

        $startTime = $timeFormatter->format(DateTime::createFromFormat('H:i', $startTime));
        if (new DateTime() < DateTime::createFromFormat('Y-m-d H:i A', $this->firstHandoff . ' ' . $startTime)) {
            $startText = $this->translate('Starts on %s');
        } else {
            $startText = $this->translate('Started on %s');
        }

        $firstHandoffInfo = new HtmlElement(
            'span',
            Attributes::create(['class' => 'rotation-info-start']),
            FormattedString::create(
                $startText,
                new HtmlElement('time', null, Text::create($firstHandoff))
            )
        );

        if ($this->mode === "24-7") {
            // Include handoff daytime in 24-7 rotations
            $handoff .= sprintf($this->translate(' at %s'), $startTime);
        }

        $handoffInterval = new HtmlElement(
            'span',
            Attributes::create(['class' => ['rotation-info-interval']]),
            Text::create($handoff)
        );

        $timeInfo = new HtmlElement(
            'div',
            Attributes::create(['class' => ['rotation-info-time']])
        );

        if ($this->mode === "partial") {
            $days = $this->rotationOptions["days"];
            $to = $timeFormatter->format(DateTime::createFromFormat('H:i', $this->rotationOptions["to"]));
            if ($days[count($days) - 1] - $days[0] === (count($days) - 1) && count($days) > 1) {
                $daysText = sprintf(
                    $this->translate(
                        '%s through %s ',
                    ),
                    $weekdayNames[reset($days)],
                    $weekdayNames[end($days)],
                );
            } else {
                $daysText = implode(', ', array_map(function ($day) use ($weekdayNames) {
                    return $weekdayNames[$day];
                }, $days));
            }

            $this->timeInfo = $timeInfo->addHtml(
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => ['days-interval-wrapper']]),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => ['rotation-info-days']]),
                        Text::create(sprintf('%s %s - %s, ', $daysText, $startTime, $to))
                    ),
                    $handoffInterval
                )
            )->addHtml($firstHandoffInfo);
        } elseif ($this->mode === "multi") {
            $fromDay = $weekdayNames[$this->rotationOptions["from_day"]];
            $toDay = $weekdayNames[$this->rotationOptions["to_day"]];
            $toAt = $timeFormatter->format(DateTime::createFromFormat('H:i', $this->rotationOptions["to_at"]));

            $this->timeInfo = $timeInfo->addHtml(
                new HtmlElement(
                    'div',
                    Attributes::create(['class' => ['days-interval-wrapper']]),
                    new HtmlElement(
                        'span',
                        Attributes::create(['class' => ['rotation-info-days']]),
                        Text::create(sprintf('%s %s - %s %s, ', $fromDay, $startTime, $toDay, $toAt))
                    ),
                    $handoffInterval
                )
            )->addHtml($firstHandoffInfo);
        } else {
            $this->timeInfo = $timeInfo->addHtml($handoffInterval)->addHtml($firstHandoffInfo);
        }

        $mode = match ($this->mode) {
            'multi' => $this->translate('Multi Day'),
            'partial' => $this->translate('Partial Day'),
            '24-7' => $this->translate('24/7')
        };

        $this->nameInfo = new HtmlElement(
            'span',
            Attributes::create(['class' => 'rotation-info-name']),
            Text::create($this->rotationName),
            new HtmlElement(
                'span',
                Attributes::create(['class' => 'rotation-info-mode']),
                Text::create(" ($mode)")
            )
        );

        return $this;
    }
}
