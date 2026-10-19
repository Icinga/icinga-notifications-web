<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Widget\EventRule;

use Icinga\Exception\NotImplementedError;
use Icinga\Module\Notifications\Common\Severity;
use InvalidArgumentException;
use ipl\Html\Attributes;
use ipl\Html\HtmlDocument;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Stdlib\Filter;
use ipl\Web\Common\CalloutType;
use ipl\Web\Filter\QueryString;
use ipl\Web\Widget\Callout;
use LogicException;

/**
 * HTML representation for an escalation condition
 */
class EscalationCondition extends HtmlDocument
{
    use Translation;

    /** @var bool Whether to describe the condition in the past tense */
    private bool $usePastTense = false;

    /**
     * Create an HTML representation for the given escalation condition
     *
     * @param Filter\Rule $condition
     */
    public function __construct(
        private Filter\Rule $condition,
    ) {
        if ($this->condition instanceof Filter\Chain && $this->condition->isEmpty()) {
            throw new InvalidArgumentException('Cannot describe an empty chain');
        }
    }

    /**
     * Create an HTML representation for the given escalation condition
     *
     * @param string $query
     *
     * @return static
     */
    public static function fromQueryString(string $query): static
    {
        return new static(QueryString::parse($query));
    }

    /**
     * Describe the condition in the past tense
     *
     * This describes the state of an incident at the time the condition matched, e.g. for an escalation that
     * already triggered. To keep the result more compact, a single severity condition is described as is,
     * instead of listing all severities it matches.
     *
     * @return $this
     */
    public function usePastTense(): static
    {
        $this->usePastTense = true;

        return $this;
    }

    protected function assemble(): void
    {
        /** @var array<string, Filter\Condition[]> $byColumn */
        $byColumn = [];
        if ($this->condition instanceof Filter\Condition) {
            $byColumn[$this->condition->getColumn()][] = $this->condition;
        } elseif ($this->condition instanceof Filter\Chain) {
            foreach ($this->condition as $rule) {
                if (! $rule instanceof Filter\Condition) {
                    throw new NotImplementedError('Unable to assemble nested chains');
                }

                $byColumn[$rule->getColumn()][] = $rule;
            }
        }

        try {
            if (isset($byColumn['is_managed'])) {
                $this->assembleIsManagedRepresentation($byColumn['is_managed']);
            }

            if (isset($byColumn['incident_severity'])) {
                $this->assembleSeverityRepresentation($byColumn['incident_severity']);
            }
        } catch (InvalidArgumentException $e) {
            $this->addHtml(new Callout(
                CalloutType::Warning,
                Text::create(sprintf(
                    $this->translate('Impossible match: %s'),
                    $e->getMessage()
                ))
            ));

            return;
        }

        if (isset($byColumn['incident_age'])) {
            if (count($byColumn['incident_age']) > 1) {
                $this->addHtml(
                    new Callout(
                        CalloutType::Warning,
                        Text::create($this->translate('Cannot describe more than one incident_age condition.'))
                    )
                );

                return;
            }

            $this->assembleAgeRepresentation((string) $byColumn['incident_age'][0]->getValue());
        }

        if ($this->isEmpty()) {
            $this->addHtml(new Callout(
                CalloutType::Warning,
                Text::create($this->translate('This escalation will never match any incident. Check its condition.'))
            ));
        }
    }

    /**
     * Assemble the representation for the given is_managed condition
     *
     * @param array $conditions
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given conditions cannot be described
     */
    protected function assembleIsManagedRepresentation(array $conditions): void
    {
        if (count($conditions) > 1) {
            throw new InvalidArgumentException('Cannot describe more than one is_managed condition');
        }

        match ($conditions[0]->getValue()) {
            'y' => $this->addPhrase(
                Text::create(
                    $this->usePastTense
                        ? $this->translate('Incident had an assigned manager')
                        : $this->translate('Incident has an assigned manager')
                )
            ),
            'n' => $this->addPhrase(
                Text::create(
                    $this->usePastTense
                        ? $this->translate('Incident had no assigned manager')
                        : $this->translate('Incident has no assigned manager')
                )
            ),
            default => throw new InvalidArgumentException('Cannot describe an invalid is_managed condition')
        };
    }

    /**
     * Assemble the representation for the given severity conditions
     *
     * @param array $conditions
     *
     * @return void
     *
     * @throws InvalidArgumentException In case the given conditions cannot be described
     */
    protected function assembleSeverityRepresentation(array $conditions): void
    {
        if ($this->usePastTense && count($conditions) === 1) {
            $condition = $conditions[0];
            $severity = $condition->getValue();
            $operator = QueryString::getRuleSymbol($condition);
            $this->addPhrase(
                Text::create(
                    sprintf(
                        match ($operator) {
                            '>' => $this->translate('Incident severity was worse than %s'),
                            '>=' => $this->translate('Incident severity was %s or worse'),
                            '<' => $this->translate('Incident severity was better than %s'),
                            '<=' => $this->translate('Incident severity was %s or better'),
                            '=' => $this->translate('Incident severity was %s'),
                            '!=' => $this->translate('Incident severity was not %s'),
                        },
                        Severity::from($severity)->getLabel()
                    )
                )
            );

            return;
        }

        $byOperator = [];
        foreach ($conditions as $condition) {
            $byOperator[QueryString::getRuleSymbol($condition)][] = $condition;
        }

        $severities = [];
        if (isset($byOperator['='])) {
            if (count($byOperator['=']) > 1) {
                throw new InvalidArgumentException('Severity cannot equal multiple expressions');
            } elseif (count($byOperator) > 1) {
                throw new InvalidArgumentException('An equal filter for severity cannot be combined with others');
            }

            $severities[] = Severity::from($byOperator['='][0]->getValue());
        }

        if (isset($byOperator['>']) || isset($byOperator['>='])) {
            if (isset($byOperator['>']) && isset($byOperator['>='])) {
                throw new InvalidArgumentException('Either `>` or `>=` should be used, but not both');
            } elseif (isset($byOperator['>']) && count($byOperator['>']) > 1) {
                throw new InvalidArgumentException('Only one condition may use the `>` range operator');
            } elseif (isset($byOperator['>=']) && count($byOperator['>=']) > 1) {
                throw new InvalidArgumentException('Only one condition may use the `>=` range operator');
            }

            $inclusive = isset($byOperator['>=']);
            $expression = ($byOperator['>'][0] ?? $byOperator['>='][0])->getValue();

            $found = false;
            foreach (Severity::cases() as $severity) {
                if ($found) {
                    $severities[] = $severity;
                } elseif ($severity->getValue() === $expression) {
                    $found = true;
                    if ($inclusive) {
                        $severities[] = $severity;
                    }
                }
            }
        }

        if (isset($byOperator['<']) || isset($byOperator['<='])) {
            if (isset($byOperator['<']) && isset($byOperator['<='])) {
                throw new InvalidArgumentException('Either `<` or `<=` should be used, but not both');
            } elseif (isset($byOperator['<']) && count($byOperator['<']) > 1) {
                throw new InvalidArgumentException('Only one condition may use the `<` range operator');
            } elseif (isset($byOperator['<=']) && count($byOperator['<=']) > 1) {
                throw new InvalidArgumentException('Only one condition may use the `<=` range operator');
            }

            $inclusive = isset($byOperator['<=']);
            $expression = ($byOperator['<'][0] ?? $byOperator['<='][0])->getValue();

            $lowerSeverities = [];
            foreach (Severity::cases() as $severity) {
                if ($severity->getValue() === $expression) {
                    if ($inclusive) {
                        $lowerSeverities[] = $severity;
                    }

                    break;
                } else {
                    $lowerSeverities[] = $severity;
                }
            }

            if (empty($severities)) {
                $severities = $lowerSeverities;
            } else {
                $severities = array_map(
                    fn (string $v) => Severity::from($v),
                    array_intersect(
                        array_map(fn (Severity $severity) => $severity->getValue(), $severities),
                        array_map(fn (Severity $severity) => $severity->getValue(), $lowerSeverities)
                    )
                );
            }
        }

        if (isset($byOperator['!='])) {
            $severities = array_map(
                fn (string $v) => Severity::from($v),
                array_diff(
                    array_map(fn (Severity $s) => $s->getValue(), $severities ?: Severity::cases()),
                    array_map(fn (Filter\Condition $condition) => $condition->getValue(), $byOperator['!='])
                )
            );
        }

        if (! empty($severities)) {
            $this->addPhrase(Text::create(sprintf(
                $this->usePastTense
                    ? $this->translatePlural(
                        'Incident severity was %1$s',
                        'Incident severity was %2$s or %1$s',
                        count($severities)
                    )
                    : $this->translatePlural(
                        'Incident severity is %1$s',
                        'Incident severity is %2$s or %1$s',
                        count($severities)
                    ),
                array_pop($severities)->getLabel(),
                implode(', ', array_map(
                    fn (Severity $severity) => $severity->getLabel(),
                    $severities
                ))
            )));
        } else {
            throw new InvalidArgumentException('No severities could be derived from the given conditions');
        }
    }

    /**
     * Assemble the representation for the given age condition
     *
     * @param string $expression
     *
     * @return void
     */
    protected function assembleAgeRepresentation(string $expression): void
    {
        preg_match('/^(\d+)([hms])$/', $expression, $matches);
        $amount = (int) $matches[1];
        $unit = $matches[2];

        $totalSeconds = match ($unit) {
            'h' => $amount * 3600,
            'm' => $amount * 60,
            's' => $amount
        };

        $days = intdiv($totalSeconds, 86400);
        $remaining = $totalSeconds % 86400;
        $hours = intdiv($remaining, 3600);
        $remaining %= 3600;
        $minutes = intdiv($remaining, 60);
        $seconds = $remaining % 60;

        if ($seconds > 0) {
            if ($days > 0 || $hours > 0) {
                // Be lazy for edge cases…
                $this->addPhrase(Text::create(sprintf($this->translate(
                    'Incident was open for at least %1$d days, %2$d hours, %3$d minutes and %4$d seconds'
                ), $days, $hours, $minutes, $seconds)));
            } else {
                if ($minutes > 0) {
                    if ($minutes === 1) {
                        $this->addPhrase(Text::create(sprintf($this->translatePlural(
                            'Incident was open for at least a minute and a second',
                            'Incident was open for at least a minute and %d seconds',
                            $seconds
                        ), $seconds)));
                    } else {
                        $this->addPhrase(Text::create(sprintf($this->translatePlural(
                            'Incident was open for at least %1$d minutes and a second',
                            'Incident was open for at least %1$d minutes and %2$d seconds',
                            $seconds
                        ), $minutes, $seconds)));
                    }
                } else {
                    $this->addPhrase(Text::create(sprintf($this->translatePlural(
                        'Incident was open for at least a second',
                        'Incident was open for at least %d seconds',
                        $seconds
                    ), $seconds)));
                }
            }
        } elseif ($minutes > 0) {
            if ($hours > 0) {
                if ($days > 0) {
                    // Be lazy for edge cases…
                    $this->addPhrase(Text::create(sprintf($this->translatePlural(
                        'Incident was open for at least %1$d days, %2$d hours and a minute',
                        'Incident was open for at least %1$d days, %2$d hours and %3$d minutes',
                        $minutes
                    ), $days, $hours, $minutes)));
                } else {
                    if ($hours === 1) {
                        $this->addPhrase(Text::create(sprintf($this->translatePlural(
                            'Incident was open for at least an hour and a minute',
                            'Incident was open for at least an hour and %d minutes',
                            $minutes
                        ), $minutes)));
                    } else {
                        $this->addPhrase(Text::create(sprintf($this->translatePlural(
                            'Incident was open for at least %1$d hours and a minute',
                            'Incident was open for at least %1$d hours and %2$d minutes',
                            $minutes
                        ), $hours, $minutes)));
                    }
                }
            } else {
                if ($days > 0) {
                    if ($days === 1) {
                        $this->addPhrase(Text::create(sprintf($this->translatePlural(
                            'Incident was open for at least a day and a minute',
                            'Incident was open for at least a day and %d minutes',
                            $minutes
                        ), $minutes)));
                    } else {
                        $this->addPhrase(Text::create(sprintf($this->translatePlural(
                            'Incident was open for at least %1$d days and a minute',
                            'Incident was open for at least %1$d days and %2$d minutes',
                            $minutes
                        ), $days, $minutes)));
                    }
                } else {
                    $this->addPhrase(Text::create(sprintf($this->translatePlural(
                        'Incident was open for at least a minute',
                        'Incident was open for at least %d minutes',
                        $minutes
                    ), $minutes)));
                }
            }
        } elseif ($hours > 0) {
            if ($days > 0) {
                if ($days === 1) {
                    $this->addPhrase(Text::create(sprintf($this->translatePlural(
                        'Incident was open for at least a day and an hour',
                        'Incident was open for at least a day and %d hours',
                        $hours
                    ), $hours)));
                } else {
                    $this->addPhrase(Text::create(sprintf($this->translatePlural(
                        'Incident was open for at least %1$d days and an hour',
                        'Incident was open for at least %1$d days and %2$d hours',
                        $hours
                    ), $days, $hours)));
                }
            } else {
                $this->addPhrase(Text::create(sprintf($this->translatePlural(
                    'Incident was open for at least an hour',
                    'Incident was open for at least %d hours',
                    $hours
                ), $hours)));
            }
        } else { // $days > 0
            $this->addPhrase(Text::create(sprintf($this->translatePlural(
                'Incident was open for at least a day',
                'Incident was open for at least %d days',
                $days
            ), $days)));
        }
    }

    /**
     * Add the given phrase to the representation, prefixed by an operator label if it is not the first phrase
     *
     * @param ValidHtml $phrase
     *
     * @return void
     */
    protected function addPhrase(ValidHtml $phrase): void
    {
        if (! $this->isEmpty()) {
            $this->addHtml(
                Text::create(' '),
                new HtmlElement(
                    'span',
                    Attributes::create(['class' => 'operator-label']),
                    Text::create(match (true) {
                        $this->condition instanceof Filter\All => $this->translate('and'),
                        $this->condition instanceof Filter\Any => $this->translate('or'),
                        $this->condition instanceof Filter\None => $this->translate('and not'),
                        default => throw new LogicException(
                            'Root filter is not a chain, nested chains are still not supported'
                        )
                    })
                ),
                Text::create(' ')
            );
        }

        $this->addHtml($phrase);
    }
}
