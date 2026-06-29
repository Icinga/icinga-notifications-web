<?php

// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Icinga\Module\Notifications\Util;

use Icinga\Module\Notifications\Util\RuleSerializer;
use ipl\Stdlib\Filter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RuleSerializerTest extends TestCase
{
    public function testGetJsonIncludesVersionAndQueryString()
    {
        $filter = Filter::equal('a', 'x');

        $result = json_decode((new RuleSerializer($filter, ['a' => ['$.a']]))->getJson(), true);

        $this->assertSame(RuleSerializer::VERSION, $result['version']);
        $this->assertSame('a=x', $result['qs']);
    }

    public function testGetJsonSerializesAllChainWithOperatorAndAttributeMapping()
    {
        $filter = Filter::all(
            Filter::equal('column1', 'value1'),
            Filter::unequal('column2', 'value2'),
            Filter::greaterThan('column3', 3),
            Filter::lessThanOrEqual('column4', 4),
        );

        $jsonPaths = [
            'column1' => ['$.a'],
            'column2' => ['$.b'],
            'column3' => ['$.c'],
            'column4' => ['$.d'],
        ];

        $result = json_decode((new RuleSerializer($filter, $jsonPaths))->getJson(), true);

        $this->assertSame('&', $result['ast']['op']);
        $this->assertSame(
            [
                ['op' => '=',  'attributes' => ['$.a'], 'value' => 'value1'],
                ['op' => '!=', 'attributes' => ['$.b'], 'value' => 'value2'],
                ['op' => '>',  'attributes' => ['$.c'], 'value' => 3],
                ['op' => '<=', 'attributes' => ['$.d'], 'value' => 4],
            ],
            $result['ast']['rules']
        );
    }

    public function testGetJsonSerializesNoneAndAnyChainsRecursively()
    {
        $filter = Filter::all(
            Filter::none(Filter::equal('a', 'x')),
            Filter::any(
                Filter::equal('a', 'y'),
                Filter::equal('a', 'z')
            ),
        );

        $result = json_decode((new RuleSerializer($filter, ['a' => ['$.a']]))->getJson(), true);

        $this->assertSame('!', $result['ast']['rules'][0]['op']);
        $this->assertSame('|', $result['ast']['rules'][1]['op']);
        $this->assertCount(2, $result['ast']['rules'][1]['rules']);
    }

    public function testGetJsonTurnsLikeIntoRegexAndKeepsLiteralsEscaped()
    {
        $filter = Filter::any(
            Filter::like('a', '*foo.bar*'),
            Filter::unlike('a', '*bar[foo]'),
        );

        $result = json_decode((new RuleSerializer($filter, ['a' => ['$.a']]))->getJson(), true);

        $this->assertArrayNotHasKey('value', $result['ast']);
        $this->assertSame('^.*foo\\.bar.*$', $result['ast']['rules'][0]['regex']);
        $this->assertSame('^.*bar\\[foo\\]$', $result['ast']['rules'][1]['regex']);
    }

    public function testGetJsonThrowsWhenColumnHasNoJsonPath()
    {
        $filter = Filter::equal('absent', 'x');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source hook did not provide a JSON path for column "absent"');

        (new RuleSerializer($filter, []))->getJson();
    }

    public function testGetJsonReturnsNullForEmptyChain()
    {
        $result = (new RuleSerializer(Filter::all(), []))->getJson();

        $this->assertNull($result);
    }

    public function testGetJsonIncludesFilterNameWhenProvided()
    {
        $filter = Filter::equal('a', 'x');

        $result = json_decode((new RuleSerializer($filter, ['a' => ['$.a']], 'my filter'))->getJson(), true);

        $this->assertSame('my filter', $result['filter_name']);
    }

    public function testGetJsonDoesNotHaveFilterNameWhenNotProvided()
    {
        $filter = Filter::equal('a', 'x');

        $result = json_decode((new RuleSerializer($filter, ['a' => ['$.a']]))->getJson(), true);

        $this->assertNotContains('filter_name', $result);
    }
}
