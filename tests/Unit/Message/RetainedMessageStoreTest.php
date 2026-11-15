<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Message;

use PhpMqtt\Broker\Message\RetainedMessageStore;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\TopicFilter;
use PHPUnit\Framework\TestCase;

final class RetainedMessageStoreTest extends TestCase
{
    public function testAllReturnsEveryMessageByTopic(): void
    {
        $store = new RetainedMessageStore();
        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        $store->store('c/d', new PublishPacket(topicName: 'c/d', payload: 'two', retain: true));

        self::assertSame(['a/b', 'c/d'], array_keys($store->all()));
        self::assertSame('two', $store->all()['c/d']->payload);
    }

    public function testAStoreChangesOnlyOnStoreAndRemove(): void
    {
        $store = new RetainedMessageStore();
        self::assertFalse($store->isDirty());

        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        self::assertTrue($store->isDirty());

        $store->markClean();
        self::assertFalse($store->isDirty());

        // Removing what is not there changes nothing, and must not cost a write.
        $store->remove('never/stored');
        self::assertFalse($store->isDirty());

        $store->remove('a/b');
        self::assertTrue($store->isDirty());
    }

    public function testARejectedStoreDoesNotDirtyTheStore(): void
    {
        $store = new RetainedMessageStore(maxMessages: 1);
        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        $store->markClean();

        self::assertFalse($store->store('c/d', new PublishPacket(topicName: 'c/d', payload: 'two', retain: true)));
        self::assertFalse($store->isDirty());
    }

    /**
     * The index must answer exactly what TopicFilter::matches answers, '$' rule and
     * parent-level '#' included: a disagreement would be an authorization bug.
     */
    public function testIndexedMatchingAgreesWithTheReferenceMatcher(): void
    {
        $topics = ['a', 'a/b', 'a/b/c', 'a/c', '/a', 'a/', '$SYS/load', '$SYS/x/y', 'b', 'b/a', 'x/y/z/w', '+a'];
        $filters = ['#', '+', '+/#', 'a/#', 'a/+', 'a/+/c', '/+', '+/', '$SYS/#', '$SYS/+', '+/b',
            'a/b/c/#', 'x/#', 'nothing/#', '/#', '+/+', 'a', '$SYS', '+/+/+/+', '$share/g/a/#', '+a', '+/a'];

        $store = new RetainedMessageStore();
        foreach ($topics as $topic) {
            $store->store($topic, new PublishPacket(topicName: $topic, payload: 'p', retain: true));
        }

        foreach ($filters as $filter) {
            $expected = array_values(array_filter($topics, static fn(string $t): bool => TopicFilter::matches($t, $filter)));
            $actual = array_map(static fn(PublishPacket $p): string => $p->topicName, $store->getMatching($filter));
            sort($expected);
            sort($actual);

            self::assertSame($expected, $actual, sprintf('Filter %s', $filter));
        }
    }

    public function testRemovedTopicsLeaveTheIndexButTheirNeighboursStay(): void
    {
        $store = new RetainedMessageStore();
        foreach (['a', 'a/b', 'a/b/c'] as $topic) {
            $store->store($topic, new PublishPacket(topicName: $topic, payload: 'p', retain: true));
        }

        $store->remove('a/b');
        self::assertSame(['a', 'a/b/c'], $this->topics($store->getMatching('a/#')));

        $store->remove('a/b/c');
        $store->remove('a');
        self::assertSame([], $store->getMatching('#'));

        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'again', retain: true));
        self::assertSame(['a/b'], $this->topics($store->getMatching('+/+')));
    }

    public function testReplacingAMessageKeepsOneEntry(): void
    {
        $store = new RetainedMessageStore();
        $store->store('a', new PublishPacket(topicName: 'a', payload: 'one', retain: true));
        $store->store('a', new PublishPacket(topicName: 'a', payload: 'two', retain: true));

        $matching = $store->getMatching('#');
        self::assertCount(1, $matching);
        self::assertSame('two', $matching[0]->payload);
    }

    /**
     * @param list<PublishPacket> $packets
     * @return list<string>
     */
    private function topics(array $packets): array
    {
        $topics = array_map(static fn(PublishPacket $p): string => $p->topicName, $packets);
        sort($topics);

        return $topics;
    }
}
