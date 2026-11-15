<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\PubcompPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrecPacket;
use PhpMqtt\Broker\Protocol\Packet\PubrelPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;

/**
 * QoS 2 is a two-step handshake, and either step may straddle a reconnect. A persistent
 * session has to carry the half-finished exchanges across it (MQTT-4.4.0-1); they were
 * dropped on every disconnect, so exactly-once became at-most-once.
 */
final class PacketHandlerQos2SessionTest extends PacketHandlerTestCase
{
    public function testAPubrelAfterReconnectRoutesTheHeldMessageOnce(): void
    {
        $subscriber = $this->subscriberTo('t');
        $publisher = $this->connect('pub', cleanSession: false);
        $this->handler->handle($publisher, new PublishPacket(topicName: 't', payload: 'exactly once', qos: 2, packetId: 7));
        $this->drop($publisher);

        $returning = $this->connect('pub', cleanSession: false);
        $this->handler->handle($returning, new PubrelPacket(7));

        self::assertSame(7, $this->lastSent($returning, PubcompPacket::class)->packetId);
        self::assertSame(['exactly once'], $this->payloadsOn($subscriber));
    }

    public function testACleanReconnectDiscardsTheHeldMessage(): void
    {
        $subscriber = $this->subscriberTo('t');
        $publisher = $this->connect('pub', cleanSession: false);
        $this->handler->handle($publisher, new PublishPacket(topicName: 't', payload: 'abandoned', qos: 2, packetId: 7));
        $this->drop($publisher);

        $returning = $this->connect('pub', cleanSession: true);
        $this->handler->handle($returning, new PubrelPacket(7));

        self::assertSame(7, $this->lastSent($returning, PubcompPacket::class)->packetId);
        self::assertSame([], $this->payloadsOn($subscriber));
    }

    public function testPubrelIsResentForADeliveryAwaitingPubcomp(): void
    {
        $subscriber = $this->connect('sub', cleanSession: false);
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 't', 'qos' => 2]]));

        $publisher = $this->connect('pub');
        $this->handler->handle($publisher, new PublishPacket(topicName: 't', payload: 'x', qos: 2, packetId: 1));
        $this->handler->handle($publisher, new PubrelPacket(1));

        $delivered = $this->lastSent($subscriber, PublishPacket::class);
        self::assertNotNull($delivered->packetId);
        $this->handler->handle($subscriber, new PubrecPacket($delivered->packetId));
        $this->drop($subscriber);

        // The client confirmed receipt; the broker owes it a PUBREL and is owed a
        // PUBCOMP. That exchange must resume, not vanish.
        $returning = $this->connect('sub', cleanSession: false);
        self::assertSame($delivered->packetId, $this->lastSent($returning, PubrelPacket::class)->packetId);
        self::assertSame(0, $this->countSent($returning, PublishPacket::class), 'Received once; not delivered again');

        $this->handler->handle($returning, new PubcompPacket($delivered->packetId));
        self::assertSame(0, $returning->getUnackedOutgoing());
    }

    private function subscriberTo(string $topic): Connection
    {
        $subscriber = $this->connect('sub');
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => $topic, 'qos' => 0]]));

        return $subscriber;
    }

    private function drop(Connection $connection): void
    {
        $this->handler->handleDisconnect($connection, false);
        $this->connections->remove($connection);
    }

    /**
     * @return list<string>
     */
    private function payloadsOn(Connection $connection): array
    {
        return array_values(array_map(
            static fn(PublishPacket $p): string => $p->payload,
            array_filter($this->sentPackets($connection), static fn($p): bool => $p instanceof PublishPacket),
        ));
    }
}
