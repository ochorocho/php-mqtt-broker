<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Message;

use PhpMqtt\Broker\Message\RetainedMessageStore;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\TopicFilter;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
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

    public function testTopicNamesCountAgainstTheByteLimit(): void
    {
        // Payload-only accounting let 10k retained topics of 64 KiB names each hold
        // 640 MB while the store reported 10 KB.
        $store = new RetainedMessageStore(maxBytes: 100);
        $topic = str_repeat('t', 200);

        self::assertFalse($store->store($topic, new PublishPacket(topicName: $topic, payload: 'x', retain: true)));
    }

    public function testPropertiesCountAgainstTheByteLimit(): void
    {
        $store = new RetainedMessageStore(maxBytes: 1000);
        $properties = new PropertyCollection();
        $properties->set(PropertyId::UserProperty, ['k', str_repeat('v', 2000)]);

        self::assertFalse($store->store('t', new PublishPacket(
            topicName: 't',
            payload: 'x',
            retain: true,
            protocolVersion: ProtocolVersion::V50,
            properties: $properties,
        )));
    }

    public function testReplacingAndRemovingGiveTheBytesBack(): void
    {
        // Room for exactly one: topic (1) + payload (9).
        $store = new RetainedMessageStore(maxBytes: 10);
        self::assertTrue($store->store('a', new PublishPacket(topicName: 'a', payload: '123456789', retain: true)));
        self::assertTrue($store->store('a', new PublishPacket(topicName: 'a', payload: '987654321', retain: true)));
        self::assertFalse($store->store('b', new PublishPacket(topicName: 'b', payload: '123456789', retain: true)));

        $store->remove('a');

        self::assertTrue($store->store('b', new PublishPacket(topicName: 'b', payload: '123456789', retain: true)));
    }

    public function testOnlyPropertiesThatTravelWithTheMessageAreRetained(): void
    {
        $properties = new PropertyCollection();
        $properties->set(PropertyId::TopicAlias, 3);
        $properties->set(PropertyId::AuthenticationData, "\xFF\x00");
        $properties->set(PropertyId::UserProperty, ['k', 'v']);
        $properties->set(PropertyId::CorrelationData, "\x01\x02");

        $store = new RetainedMessageStore();
        $store->store('t', new PublishPacket(
            topicName: 't',
            payload: 'x',
            retain: true,
            protocolVersion: ProtocolVersion::V50,
            properties: $properties,
        ));

        // A topic alias belongs to one connection, authentication data to one
        // exchange; neither means anything to a later subscriber, so neither is kept.
        $retained = $store->all()['t']->properties;
        self::assertNotNull($retained);
        self::assertNull($retained->get(PropertyId::TopicAlias));
        self::assertNull($retained->get(PropertyId::AuthenticationData));
        self::assertSame([['k', 'v']], $retained->get(PropertyId::UserProperty));
        self::assertSame("\x01\x02", $retained->get(PropertyId::CorrelationData));
    }
}
