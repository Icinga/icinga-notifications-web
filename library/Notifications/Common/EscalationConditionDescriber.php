<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Common;

use ipl\I18n\Translation;
use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Stdlib\Filter\Rule;
use ipl\Web\Filter\QueryString;

class EscalationConditionDescriber
{
    use Translation;

    /**
     * Describe the escalation conditions as a human-readable string
     *
     * @param Rule|string|null $rule
     *
     * @return string
     */
    public static function describe(Rule|string|null $rule): string
    {
        $instance = new self();

        if (! $rule instanceof Rule) {
            $rule = QueryString::parse($rule ?? '');
        }

        if ($rule instanceof Chain && $rule->isEmpty()) {
            return $instance->translate('Immediately');
        } elseif ($rule instanceof Condition) {
            $rule = [$rule];
        }

        $filters = [];
        foreach ($rule as $condition) {
            $filters[$condition->getColumn()][] = [$condition->getValue(), QueryString::getRuleSymbol($condition)];
        }

        $parts = [];
        if (isset($filters['incident_severity'])) {
            $parts[] = $instance->describeSeverity($filters['incident_severity']);
        }

        if (isset($filters['incident_age'])) {
            [$value] = $filters['incident_age'][0];
            $parts[] = sprintf(
                $instance->translate('Incident age exceeds %s'),
                $instance->formatAge($value)
            );
        }

        return implode($instance->translate(' and '), $parts);
    }

    /**
     * Describe the severity conditions as a human-readable string
     *
     * When multiple conditions are given, the condition values are sorted first by severity order to create a range.
     *
     * @param array<array{string, string}> $conditions
     *
     * @return string
     */
    private function describeSeverity(array $conditions): string
    {
        if (count($conditions) === 1) {
            [$value, $operator] = $conditions[0];
            $label = Severity::from($value)->getLabel();

            return match ($operator) {
                '>'  => sprintf($this->translate('Severity exceeds %s'), $label),
                '>=' => sprintf($this->translate('Severity is at least %s'), $label),
                '<'  => sprintf($this->translate('Severity is below %s'), $label),
                '<=' => sprintf($this->translate('Severity is at most %s'), $label),
                '!=' => sprintf($this->translate('Severity is not %s'), $label),
                '='  => sprintf($this->translate('Severity equals %s'), $label)
            };
        }

        $values = array_column($conditions, 0);
        $severityOrder = array_column(Severity::cases(), 'value');
        usort($values, function (string $a, string $b) use ($severityOrder): int {
            return array_search($a, $severityOrder, true) <=> array_search($b, $severityOrder, true);
        });

        return sprintf(
            $this->translate('Severity enters the range %s – %s'),
            Severity::from($values[0])->getLabel(),
            Severity::from($values[1])->getLabel()
        );
    }

    /**
     * Format the age
     *
     * @param string $age
     *
     * @return string
     */
    private function formatAge(string $age): string
    {
        preg_match('/^(\d+)([hms])$/', $age, $matches);
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

        $parts = [];
        if ($days > 0) {
            $parts[] = sprintf(
                $this->translatePlural('%d day', '%d days', $days),
                $days
            );
        }

        if ($hours > 0) {
            $parts[] = sprintf(
                $this->translatePlural('%d hour', '%d hours', $hours),
                $hours
            );
        }

        if ($minutes > 0) {
            $parts[] = sprintf(
                $this->translatePlural('%d minute', '%d minutes', $minutes),
                $minutes
            );
        }

        if ($seconds > 0 || empty($parts)) {
            $parts[] = sprintf(
                $this->translatePlural('%d second', '%d seconds', $seconds),
                $seconds
            );
        }

        return implode(' ', $parts);
    }
}
