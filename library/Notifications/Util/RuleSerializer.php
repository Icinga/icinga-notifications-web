<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Util;

use Icinga\Util\Json;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Chain;

class RuleSerializer
{
    protected Filter\Rule $filter;

    protected array $metadataKeys = [];

    /**
     * Create an object that can be used to serialize a rule to JSON
     *
     * @param Filter\Rule $filter
     */
    public function __construct(Filter\Rule $filter, array $metadataKeys = [])
    {
        $this->filter = $filter;
        $this->metadataKeys = $metadataKeys;
    }

    /**
     * Serialize the filter as Json
     *
     * @return string
     *
     * @throws \Icinga\Exception\Json\JsonEncodeException
     */
    public function getJson(): string
    {
        if ($this->filter instanceof Filter\Chain) {
            $result = $this->serializeChain($this->filter);
        } else {
            /** @var Filter\Condition $this->filter */
            $result = $this->serializeCondition($this->filter);
        }

        return Json::encode($result);
    }

    /**
     * Create an array with keys `op` and `rules` from a chain
     *
     * @param Chain $chain
     *
     * @return array{op: string, rules: array}
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
     * Create an array with the keys `op`, `column` and `value` from a condition
     *
     * @param Filter\Condition $condition
     *
     * @return array{op: string, column: string, value: mixed}
     */
    protected function serializeCondition(Filter\Condition $condition): array
    {
        return [
            'op' => match (true) {
                $condition instanceof Filter\Unlike => '!~',
                $condition instanceof Filter\Unequal => '!=',
                $condition instanceof Filter\Like => '~',
                $condition instanceof Filter\Equal => '=',
                $condition instanceof Filter\GreaterThan => '>',
                $condition instanceof Filter\LessThan => '<',
                $condition instanceof Filter\GreaterThanOrEqual => '>=',
                $condition instanceof Filter\LessThanOrEqual => '<=',
            },
            'column' => $condition->metaData()->get('jsonPath'),
            'value' => $condition->getValue(),
            'columnName' => $condition->getColumn(),
            'metadata' => $this->serializeConditionMetadata($condition),
        ];
    }

    /**
     * Serialize the metadata of a condtion into an array
     *
     * @param Filter\Condition $condition
     *
     * @return array<string, mixed>
     */
    protected function serializeConditionMetadata(Filter\Condition $condition): array
    {
        $result = [];
        foreach ($this->metadataKeys as $key) {
            $result[$key] = $condition->metaData()->get($key);
        }

        return $result;
    }
}
