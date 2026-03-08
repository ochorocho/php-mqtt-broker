<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Subscription;

use PhpMqtt\Broker\Subscription\Subscription;
use PhpMqtt\Broker\Subscription\TopicMatcher;
use PHPUnit\Framework\TestCase;

final class TopicMatcherTest extends TestCase
{
    private TopicMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new TopicMatcher();
    }

    public function testExactMatch(): void
    {
        $sub = new Subscription('c1', 'sport/tennis', 0);
        $this->matcher->subscribe('sport/tennis', $sub);

        $results = $this->matcher->match('sport/tennis');
        self::assertCount(1, $results);
        self::assertSame('c1', $results[0]->clientId);
    }

    public function testNoMatch(): void
    {
        $sub = new Subscription('c1', 'sport/tennis', 0);
        $this->matcher->subscribe('sport/tennis', $sub);

        $results = $this->matcher->match('sport/football');
        self::assertCount(0, $results);
    }

    public function testSingleLevelWildcard(): void
    {
        $sub = new Subscription('c1', 'sport/+/player', 0);
        $this->matcher->subscribe('sport/+/player', $sub);

        $results = $this->matcher->match('sport/tennis/player');
        self::assertCount(1, $results);
    }

    public function testSingleLevelWildcardNoDeeper(): void
    {
        $sub = new Subscription('c1', 'sport/+', 0);
        $this->matcher->subscribe('sport/+', $sub);

        $results = $this->matcher->match('sport/tennis/player');
        self::assertCount(0, $results);
    }

    public function testMultiLevelWildcard(): void
    {
        $sub = new Subscription('c1', 'sport/#', 0);
        $this->matcher->subscribe('sport/#', $sub);

        $results = $this->matcher->match('sport/tennis/player');
        self::assertCount(1, $results);
    }

    public function testMultiLevelWildcardAlone(): void
    {
        $sub = new Subscription('c1', '#', 0);
        $this->matcher->subscribe('#', $sub);

        $results = $this->matcher->match('any/topic/here');
        self::assertCount(1, $results);
    }

    public function testMultiLevelWildcardMatchesParent(): void
    {
        $sub = new Subscription('c1', 'sport/#', 0);
        $this->matcher->subscribe('sport/#', $sub);

        // sport/# should match "sport" itself (zero remaining levels)
        $results = $this->matcher->match('sport');
        self::assertCount(1, $results);
    }

    public function testDollarTopicNotMatchedByHash(): void
    {
        $sub = new Subscription('c1', '#', 0);
        $this->matcher->subscribe('#', $sub);

        $results = $this->matcher->match('$SYS/info');
        self::assertCount(0, $results);
    }

    public function testDollarTopicNotMatchedByPlus(): void
    {
        $sub = new Subscription('c1', '+/info', 0);
        $this->matcher->subscribe('+/info', $sub);

        $results = $this->matcher->match('$SYS/info');
        self::assertCount(0, $results);
    }

    public function testDollarTopicExplicitMatch(): void
    {
        $sub = new Subscription('c1', '$SYS/info', 0);
        $this->matcher->subscribe('$SYS/info', $sub);

        $results = $this->matcher->match('$SYS/info');
        self::assertCount(1, $results);
    }

    public function testDollarTopicWildcardAfterExplicit(): void
    {
        $sub = new Subscription('c1', '$SYS/#', 0);
        $this->matcher->subscribe('$SYS/#', $sub);

        $results = $this->matcher->match('$SYS/info');
        self::assertCount(1, $results);
    }

    public function testEmptyTopicLevel(): void
    {
        $sub = new Subscription('c1', 'a/+/b', 0);
        $this->matcher->subscribe('a/+/b', $sub);

        // a//b has 3 levels: "a", "", "b" — + should match the empty level
        $results = $this->matcher->match('a//b');
        self::assertCount(1, $results);
    }

    public function testMultipleSubscribersMatch(): void
    {
        $sub1 = new Subscription('c1', 'test/topic', 0);
        $sub2 = new Subscription('c2', 'test/+', 0);
        $this->matcher->subscribe('test/topic', $sub1);
        $this->matcher->subscribe('test/+', $sub2);

        $results = $this->matcher->match('test/topic');
        self::assertCount(2, $results);
    }

    public function testUnsubscribeRemoves(): void
    {
        $sub = new Subscription('c1', 'test/topic', 0);
        $this->matcher->subscribe('test/topic', $sub);

        $this->matcher->unsubscribe('test/topic', 'c1');

        $results = $this->matcher->match('test/topic');
        self::assertCount(0, $results);
    }

    public function testRemoveClientClearsAll(): void
    {
        $sub1 = new Subscription('c1', 'topic/a', 0);
        $sub2 = new Subscription('c1', 'topic/b', 0);
        $sub3 = new Subscription('c2', 'topic/a', 0);

        $this->matcher->subscribe('topic/a', $sub1);
        $this->matcher->subscribe('topic/b', $sub2);
        $this->matcher->subscribe('topic/a', $sub3);

        $this->matcher->removeClient('c1');

        $resultsA = $this->matcher->match('topic/a');
        $resultsB = $this->matcher->match('topic/b');

        self::assertCount(1, $resultsA); // c2 still present
        self::assertCount(0, $resultsB);
    }

    public function testSingleLevelWildcardAtRoot(): void
    {
        $sub = new Subscription('c1', '+', 0);
        $this->matcher->subscribe('+', $sub);

        $results = $this->matcher->match('test');
        self::assertCount(1, $results);

        $results = $this->matcher->match('test/deep');
        self::assertCount(0, $results);
    }
}
