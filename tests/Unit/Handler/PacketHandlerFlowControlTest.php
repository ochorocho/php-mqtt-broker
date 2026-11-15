<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * What one client can make the broker send, hold or compute.
 */
final class PacketHandlerFlowControlTest extends PacketHandlerTestCase
{
    public function testRepeatedFilterInOneSubscribeDeliversRetainedMessagesOnce(): void
    {
        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'r/1', payload: 'x', retain: true));
        $this->handler->handle($publisher, new PublishPacket(topicName: 'r/2', payload: 'y', retain: true));

        // One SUBSCRIBE naming '#' many times used to re-send every retained message
        // for every repetition: a ~1 MiB packet could ask for 262k copies of the store.
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, array_fill(0, 50, ['topic' => '#', 'qos' => 0])));

        self::assertSame(2, $this->countSent($subscriber, PublishPacket::class));
        self::assertCount(50, $this->lastSent($subscriber, SubackPacket::class)->returnCodes);
    }

    public function testFiltersBeyondThePerPacketCapAreRefused(): void
    {
        $this->makeHandler(config: new Configuration(maxFiltersPerSubscribe: 2));

        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [
            ['topic' => 'a', 'qos' => 0],
            ['topic' => 'b', 'qos' => 1],
            ['topic' => 'c', 'qos' => 0],
        ]));

        self::assertSame([0x00, 0x01, 0x80], $this->lastSent($subscriber, SubackPacket::class)->returnCodes);
        self::assertFalse($this->subscriptions->hasSubscription('subscriber', 'c'));
    }

    public function testFiltersBeyondTheCapAreAQuotaRefusalInMqtt5(): void
    {
        $this->makeHandler(config: new Configuration(maxFiltersPerSubscribe: 1));

        $subscriber = $this->connect('subscriber', version: ProtocolVersion::V50);
        $this->handler->handle($subscriber, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 'a', 'qos' => 0], ['topic' => 'b', 'qos' => 0]],
            protocolVersion: ProtocolVersion::V50,
        ));

        self::assertSame(
            [0x00, 0x97],
            $this->lastSent($subscriber, SubackPacket::class, ProtocolVersion::V50)->returnCodes,
        );
    }

    public function testMqtt311ClientsAreHeldToTheServerInflightLimit(): void
    {
        $this->makeHandler(config: new Configuration(maxInflightMessages: 2));
        $subscriber = $this->subscribedAtQos1();

        $this->publishQos1Messages(3);

        // 3.1.1 has no Receive Maximum, so the broker used to assume 65535 messages in
        // flight: a subscriber that never acknowledged pinned all of them in memory.
        self::assertSame(2, $this->countSent($subscriber, PublishPacket::class));
    }

    public function testAnAcknowledgementForAnUnknownPacketDoesNotOpenTheWindow(): void
    {
        $this->makeHandler(config: new Configuration(maxInflightMessages: 2));
        $subscriber = $this->subscribedAtQos1();
        $this->publishQos1Messages(3);

        $this->handler->handle($subscriber, new PubackPacket(packetId: 999));
        $this->handler->handle($subscriber, new PubcompPacket(packetId: 998));

        self::assertSame(2, $this->countSent($subscriber, PublishPacket::class));

        // A real acknowledgement frees a slot, and the queued message goes out.
        $first = array_values(array_filter(
            $this->sentPackets($subscriber),
            static fn($packet): bool => $packet instanceof PublishPacket,
        ))[0];
        self::assertInstanceOf(PublishPacket::class, $first);
        self::assertNotNull($first->packetId);
        $this->handler->handle($subscriber, new PubackPacket(packetId: $first->packetId));

        self::assertSame(3, $this->countSent($subscriber, PublishPacket::class));
    }

    public function testAClientsOwnReceiveMaximumStillAppliesWhenLower(): void
    {
        $this->makeHandler(config: new Configuration(maxInflightMessages: 5));

        $properties = new PropertyCollection();
        $properties->set(PropertyId::ReceiveMaximum, 1);
        $subscriber = $this->connect('subscriber', packet: new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V50->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'subscriber',
            properties: $properties,
        ));
        $this->handler->handle($subscriber, new SubscribePacket(
            packetId: 1,
            subscriptions: [['topic' => 't', 'qos' => 1]],
            protocolVersion: ProtocolVersion::V50,
        ));

        $this->publishQos1Messages(2);

        self::assertSame(1, $this->countSent($subscriber, PublishPacket::class, ProtocolVersion::V50));
    }

    private function subscribedAtQos1(): \PhpMqtt\Broker\Connection\Connection
    {
        $subscriber = $this->connect('subscriber');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 't', 'qos' => 1]]));

        return $subscriber;
    }

    private function publishQos1Messages(int $count): void
    {
        $publisher = $this->connect('publisher');
        for ($i = 1; $i <= $count; $i++) {
            $this->handler->handle($publisher, new PublishPacket(
                topicName: 't',
                payload: 'm' . $i,
                qos: 1,
                packetId: $i,
            ));
        }
    }
}
