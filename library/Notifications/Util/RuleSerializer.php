<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Util;

use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Util\Json;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Chain;
use ipl\Web\Filter\QueryString;
use RuntimeException;

class RuleSerializer
{
    /** @var int The filterr version */
    public const VERSION = 2;

    /** @var Filter\Condition|Filter\Chain */
    protected Filter\Rule $filter;

    /** @var array<string, string[]> JSON paths keyed by column name */
    protected array $jsonPaths;

    /**
     * Create an object that can be used to serialize a rule to JSON
     *
     * @param Filter\Rule $filter
     * @param array<string, string[]> $jsonPaths JSON paths keyed by column name
     */
    public function __construct(Filter\Rule $filter, array $jsonPaths)
    {
        $this->filter = $filter;
        $this->jsonPaths = $jsonPaths;
    }

    /**
     * Serialize the filter as Json
     *
     * @return string
     *
     * @throws JsonEncodeException
     */
    public function getJson(): string
    {
        $result = [
            'version' => self::VERSION,
            'qs'      => QueryString::render($this->filter),
        ];
        if ($this->filter instanceof Filter\Chain) {
            $result['ast'] = $this->serializeChain($this->filter);
        } else {
            $result['ast'] = $this->serializeCondition($this->filter);
        }

        return Json::encode($result);
    }

    /**
     * Create an array with keys `op` and `rules` from a chain
     *
     * @param Chain $chain
     *
     * @return array{op: string, rules: list<array<string, mixed>>}
     */
    protected function serializeChain(Chain $chain): array
    {
        $result = [
            'op' => match (true) {
                $chain instanceof Filter\All => '&',
                $chain instanceof Filter\None => '!',
                $chain instanceof Filter\Any => '|'
            }
        ];

        $rules = [];
        foreach ($chain as $rule) {
            if ($rule instanceof Chain) {
                $rules[] = $this->serializeChain($rule);
            } else {
                $rules[] = $this->serializeCondition($rule);
            }
        }

        $result['rules'] = $rules;

        return $result;
    }

    /**
     * Create an array with the keys `op`, `attributes` and either `value` or `regex`
     *
     * @param Filter\Condition $condition
     *
     * @return array{op: string, attributes: list<string>, value: string}
     *       | array{op: string, attributes: list<string>, regex: string}
     *
     * @throws RuntimeException If the source hook did not provide a JSON path for the condition's column
     */
    protected function serializeCondition(Filter\Condition $condition): array
    {
        $op = match (true) {
            $condition instanceof Filter\Unlike, $condition instanceof Filter\Unequal => '!=',
            $condition instanceof Filter\Like, $condition instanceof Filter\Equal => '=',
            $condition instanceof Filter\GreaterThan => '>',
            $condition instanceof Filter\LessThan => '<',
            $condition instanceof Filter\GreaterThanOrEqual => '>=',
            $condition instanceof Filter\LessThanOrEqual => '<=',
        };

        $value = $condition instanceof Filter\Like || $condition instanceof Filter\Unlike
            ? ['regex' => $this->createRegularExpression($condition->getValue())]
            : ['value' => $condition->getValue()];

        $column = $condition->getColumn();
        if (
            ! isset($this->jsonPaths[$column])
            || ! is_array($this->jsonPaths[$column])
            || empty($this->jsonPaths[$column])
        ) {
            throw new RuntimeException(sprintf(
                'Source hook did not provide a JSON path for column "%s"',
                $column
            ));
        }

        return [
            'op' => $op,
            'attributes' => $this->jsonPaths[$column],
            ...$value,
        ];
    }

    /**
     * Get the preprocessed value of a condition
     *
     * Creates a regex for Like and Unlike rules
     *
     * @param string $value
     *
     * @return string
     */
    protected function createRegularExpression(string $value): string
    {
        return '^' . str_replace('\*', '.*', preg_quote($value)) . '$';
    }
}
