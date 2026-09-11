<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Protocol;

use PhpMqtt\Broker\Protocol\TopicFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TopicFilterTest extends TestCase
{
    #[DataProvider('validNames')]
    public function testAcceptsValidTopicNames(string $topicName): void
    {
        self::assertTrue(TopicFilter::isValidName($topicName));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'single level' => ['sport'];
        yield 'multi level' => ['sport/tennis/player1'];
        yield 'dollar topic' => ['$SYS/broker/uptime'];
        yield 'empty level' => ['a//b'];
        yield 'space' => ['a b/c'];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidTopicNames(string $topicName): void
    {
        self::assertFalse(TopicFilter::isValidName($topicName));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        // A PUBLISH carries a name, never a filter (MQTT-3.3.2-2).
        yield 'multi-level wildcard' => ['sport/#'];
        yield 'single-level wildcard' => ['sport/+/player'];
        yield 'bare hash' => ['#'];
        yield 'bare plus' => ['+'];
        yield 'empty' => [''];
    }

    #[DataProvider('validFilters')]
    public function testAcceptsValidFilters(string $filter): void
    {
        self::assertTrue(TopicFilter::isValidFilter($filter));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validFilters(): iterable
    {
        yield 'literal' => ['sport/tennis'];
        yield 'trailing hash' => ['sport/#'];
        yield 'bare hash' => ['#'];
        yield 'bare plus' => ['+'];
        yield 'plus levels' => ['+/+'];
        yield 'mixed' => ['sport/+/player/#'];
        yield 'shared' => ['$share/group/sport/#'];
    }

    #[DataProvider('invalidFilters')]
    public function testRejectsInvalidFilters(string $filter): void
    {
        self::assertFalse(TopicFilter::isValidFilter($filter));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFilters(): iterable
    {
        // '#' must be the last level, and a wildcard must occupy a whole level.
        yield 'hash not last' => ['sport/#/player'];
        yield 'hash inside level' => ['sport#'];
        yield 'plus inside level' => ['sp+rt'];
        yield 'plus suffix' => ['sport/+x'];
        yield 'empty' => [''];
    }

    #[DataProvider('sharedFilters')]
    public function testValidatesSharedSubscriptions(string $filter, bool $expected): void
    {
        self::assertSame($expected, TopicFilter::isValidSharedFilter($filter));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sharedFilters(): iterable
    {
        yield 'well formed' => ['$share/group/a/b', true];
        yield 'not shared at all' => ['a/b', true];
        yield 'no group or filter' => ['$share/', false];
        yield 'group but no filter' => ['$share/group', false];
        yield 'empty group' => ['$share//topic', false];
        yield 'wildcard in group' => ['$share/+/topic', false];
    }

    public function testStripsSharedPrefixForMatching(): void
    {
        self::assertSame('a/b', TopicFilter::stripSharedPrefix('$share/group/a/b'));
        self::assertSame('group', TopicFilter::sharedGroup('$share/group/a/b'));

        // A plain filter is returned untouched and has no group.
        self::assertSame('a/b', TopicFilter::stripSharedPrefix('a/b'));
        self::assertNull(TopicFilter::sharedGroup('a/b'));
    }

    #[DataProvider('matchCases')]
    public function testMatches(string $topic, string $filter, bool $expected): void
    {
        self::assertSame($expected, TopicFilter::matches($topic, $filter));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matchCases(): iterable
    {
        yield 'exact' => ['sport/tennis', 'sport/tennis', true];
        yield 'plus matches one level' => ['sport/tennis', 'sport/+', true];
        yield 'plus does not span levels' => ['sport/tennis/x', 'sport/+', false];
        yield 'hash matches descendants' => ['sport/tennis/x', 'sport/#', true];

        // '#' also matches its own parent level (MQTT-4.7.1-2).
        yield 'hash matches parent' => ['sport', 'sport/#', true];

        // A leading '$' is hidden from a wildcard at the first level (MQTT-4.7.2-1).
        yield 'dollar hidden from hash' => ['$SYS/uptime', '#', false];
        yield 'dollar hidden from plus' => ['$SYS/uptime', '+/uptime', false];
        yield 'dollar reachable explicitly' => ['$SYS/uptime', '$SYS/#', true];
        yield 'dollar plus below first level' => ['$SYS/uptime', '$SYS/+', true];

        yield 'shared filter matches stripped' => ['a/b', '$share/group/a/b', true];
        yield 'no match' => ['sport/tennis', 'sport/football', false];
    }
}
