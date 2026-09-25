<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Util;

use Icinga\Exception\Json\JsonEncodeException;
use Icinga\Util\Json;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Rule;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class RuleSerializer
{
    /** @var int The filter version */
    public const VERSION = 2;

    /**
     * Create an object that can be used to serialize a rule to JSON
     *
     * @param Rule $filter
     * @param string $queryString
     * @param array<string, string[]> $jsonPaths JSON paths keyed by column name, leave empty to use column names only
     * @param bool $assisted Whether the filter was created with a source integration
     * @param ?string $filterName The name of the filter
     */
    public function __construct(
        private readonly Filter\Rule $filter,
        private readonly string $queryString,
        private readonly array $jsonPaths = [],
        private readonly bool $assisted = false,
        private readonly ?string $filterName = null
    ) {
    }

    /**
     * Decode a serialized rule and verify its version
     *
     * @param string $json The serialized rule, as created by {@see static::getJson()}
     *
     * @return array{version: int, qs: string, assisted: bool, filter_name?: string, ast: array}
     *
     * @throws JsonException
     * @throws UnexpectedValueException If the version does not match {@see static::VERSION}
     */
    public static function decode(string $json): array
    {
        if (empty($json)) {
            return [
                'version' => self::VERSION,
                'qs' => '',
                'assisted' => false,
                'ast' => []
            ];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $version = $decoded['version'] ?? null;
        if ($version !== self::VERSION) {
            throw new UnexpectedValueException(sprintf(
                'Filter version \'%s\' is not supported (expected %d)',
                $version,
                self::VERSION
            ));
        }

        return $decoded;
    }

    /**
     * Serialize the filter as Json
     *
     * @return string The serialized filter, `''` for an empty chain
     *
     * @throws JsonEncodeException
     */
    public function getJson(): string
    {
        $result = [
            'version'  => self::VERSION,
            'qs'       => $this->queryString,
            'assisted' => $this->assisted,
        ];

        if ($this->filterName) {
            $result['filter_name'] = $this->filterName;
        }

        if ($this->filter instanceof Filter\Chain) {
            if ($this->filter->isEmpty()) {
                return '';
            }

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
        if (empty($this->jsonPaths)) {
            $attributes = [$column];
        } elseif (
            isset($this->jsonPaths[$column])
            && is_array($this->jsonPaths[$column])
            && ! empty($this->jsonPaths[$column])
        ) {
            $attributes = $this->jsonPaths[$column];
        } else {
            throw new RuntimeException(sprintf(
                'Source hook did not provide a JSON path for column "%s"',
                $column
            ));
        }

        return [
            'op' => $op,
            'attributes' => $attributes,
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
