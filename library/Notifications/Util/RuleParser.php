<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Util;

use ipl\Stdlib\Filter;

class RuleParser
{
    public function parseJson(string $json): Filter\Rule
    {
        return $this->parseRule(json_decode($json, true));
    }

    /**
     * Parse a serialized rule
     *
     * @param array $rule A rule serialized as json
     *
     * @return Filter\Rule
     */
    public function parseRule(array $rule): Filter\Rule
    {
        if (in_array($rule['op'], ['&', '|', '!'])) {
            return $this->parseChain($rule);
        } else {
            return $this->parseCondition($rule);
        }
    }

    protected function parseChain(array $data): Filter\Chain
    {
        $rules = [];
        foreach ($data['rules'] as $rule) {
            $rules[] = $this->parseRule($rule);
        }


        return match ($data['op']) {
            '&' => new Filter\All(...$rules),
            '|' => new Filter\Any(...$rules),
            '!' => new Filter\None(...$rules),
        };
    }

    protected function parseCondition(array $data): Filter\Condition
    {
        $condition = match ($data['op']) {
            '!~' => new Filter\Unlike($data['columnName'], $data['value']),
            '!=' => new Filter\Unequal($data['columnName'], $data['value']),
            '~' => new Filter\Like($data['columnName'], $data['value']),
            '=' => new Filter\Equal($data['columnName'], $data['value']),
            '>' => new Filter\GreaterThan($data['columnName'], $data['value']),
            '<' => new Filter\LessThan($data['columnName'], $data['value']),
            '>=' => new Filter\GreaterThanOrEqual($data['columnName'], $data['value']),
            '<=' => new Filter\LessThanOrEqual($data['columnName'], $data['value']),
        };

        $condition->metaData()->set('jsonPath', $data['column']);

        foreach ($data['metadata'] ?? [] as $key => $value) {
            $condition->metaData()->set($key, $value);
        }

        return $condition;
    }
}
