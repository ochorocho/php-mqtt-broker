<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\DisconnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * A topic or filter could be up to 64 KiB long, and only filters were held to the level
 * limit. A thousand subscriptions per client at that length is 64 MiB of routing table
 * for one client; retained topic names were not bounded at all.
 */
final class PacketHandlerTopicLimitsTest extends PacketHandlerTestCase
{
    protected function setUp(): void
    {
        $this->makeHandler(config: new Configuration(maxTopicLevels: 3, maxTopicLength: 20));
    }

    public function testAnOverlongFilterIsRefused(): void
    {
        $connection = $this->connect('client');
        $this->handler->handle($connection, new SubscribePacket(1, [
            ['topic' => str_repeat('a', 21), 'qos' => 0],
            ['topic' => 'short', 'qos' => 0],
        ]));

        self::assertSame([0x80, 0x00], $this->lastSent($connection, SubackPacket::class)->returnCodes);
    }

    public function testASharedPrefixIsNotCountedAsLevels(): void
    {
        $connection = $this->connect('client', version: ProtocolVersion::V50);
        $this->handler->handle($connection, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => '$share/g/a/b/c', 'qos' => 0]],
            protocolVersion: ProtocolVersion::V50,
        ));

        // Three levels of filter behind the prefix: within the limit, though the raw
        // string has five.
        self::assertSame([0x00], $this->lastSent($connection, SubackPacket::class, ProtocolVersion::V50)->returnCodes);
    }

    public function testAnOverlongPublishIsNeitherRoutedNorRetained(): void
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => '#', 'qos' => 0]]));

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: str_repeat('t', 21), payload: 'x', retain: true));

        self::assertSame(0, $this->countSent($subscriber, PublishPacket::class));
        self::assertSame(0, $this->handler->getRetainedMessages()->count());
        self::assertTrue($publisher->isConnected());
    }

    public function testATooDeepPublishIsTopicNameInvalidIn5(): void
    {
        $publisher = $this->connect('publisher', version: ProtocolVersion::V50);
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'a/b/c/d',
            payload: 'x',
            qos: 1,
            packetId: 5,
            protocolVersion: ProtocolVersion::V50,
        ));

        $puback = $this->lastSent($publisher, PubackPacket::class, ProtocolVersion::V50);
        self::assertSame(5, $puback->packetId);
        self::assertSame(0x90, $puback->reasonCode);
    }

    public function testARefusedTopicIsNotRegisteredAsAnAlias(): void
    {
        $publisher = $this->connect('publisher', version: ProtocolVersion::V50);
        $this->handler->handle($publisher, $this->aliased(str_repeat('t', 21), 1));
        $this->handler->handle($publisher, $this->aliased('', 1));

        // The second PUBLISH names alias 1 alone; it must not resolve to the refused topic.
        self::assertSame(0x94, $this->lastSent($publisher, DisconnectPacket::class, ProtocolVersion::V50)->reasonCode);
    }

    public function testAWillOnAnOverlongTopicRefusesTheConnect(): void
    {
        $v311 = $this->connectWithWill(str_repeat('w', 21), ProtocolVersion::V311);
        $v5 = $this->connectWithWill(str_repeat('w', 21), ProtocolVersion::V50);

        self::assertSame(0x05, $this->lastSent($v311, ConnackPacket::class)->returnCode);
        self::assertSame(0x90, $this->lastSent($v5, ConnackPacket::class, ProtocolVersion::V50)->returnCode);
    }

    public function testAWillTopicWithAWildcardRefusesTheConnect(): void
    {
        // A will is published like any message, and a topic name may not hold a
        // wildcard. The will topic was never checked for one.
        $connection = $this->connectWithWill('status/#', ProtocolVersion::V311);

        self::assertFalse($connection->isConnected());
        self::assertSame(0x05, $this->lastSent($connection, ConnackPacket::class)->returnCode);
    }

    private function aliased(string $topic, int $alias): PublishPacket
    {
        $properties = new PropertyCollection();
        $properties->set(PropertyId::TopicAlias, $alias);

        return new PublishPacket(topicName: $topic, payload: 'x', protocolVersion: ProtocolVersion::V50, properties: $properties);
    }

    private function connectWithWill(string $willTopic, ProtocolVersion $version): Connection
    {
        return $this->connect('client-' . $version->value, packet: new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: $version->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'client-' . $version->value,
            hasWill: true,
            willTopic: $willTopic,
            willPayload: 'gone',
        ));
    }
}
