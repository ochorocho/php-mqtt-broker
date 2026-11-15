<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PubackPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * Messages kept for later - queued past the client's window, or unacknowledged when it
 * went away - are replayed on a different connection than the one they were built for.
 * Anything that belonged to the old connection must not travel with them.
 */
final class PacketHandlerReplayTest extends PacketHandlerTestCase
{
    public function testReplayedMessagesResolveToTheirTopicOnTheNewConnection(): void
    {
        $subscriber = $this->v5Subscriber(receiveMaximum: 1);
        $this->publishQos1('t/a', 3);
        $this->dropConnection($subscriber);

        // Queued while the window was full, these used to be stored as they would have
        // been sent then: topic '' plus an alias. The new connection has no aliases, so
        // the client could not tell what topic they were for.
        $returning = $this->v5Subscriber(receiveMaximum: 1);
        $topics = $this->acknowledgeEverything($returning);

        self::assertSame(['t/a', 't/a', 't/a'], $topics);

        // m1 was in flight when the client left, m2 and m3 still queued: it was sent
        // first and is redelivered first, not behind messages queued after it.
        self::assertSame(['m1', 'm2', 'm3'], array_map(
            static fn(PublishPacket $p): string => $p->payload,
            $this->publishesOn($returning, ProtocolVersion::V50),
        ));
    }

    public function testReplayStopsAtTheClientsReceiveMaximum(): void
    {
        $subscriber = $this->v5Subscriber(receiveMaximum: 2);
        $this->dropConnection($subscriber);
        $this->publishQos1('t/a', 5);

        // Every queued message was sent at once on reconnect, however small the
        // window the client had just asked for.
        $returning = $this->v5Subscriber(receiveMaximum: 2);

        self::assertSame(2, $this->countSent($returning, PublishPacket::class, ProtocolVersion::V50));
    }

    public function testAMessageQueuedFor5IsAValid311PacketWhenTheClientReturnsAs311(): void
    {
        $this->makeHandler(config: new Configuration(maxInflightMessages: 1));
        $subscriber = $this->v5Subscriber();
        $this->dropConnection($subscriber);
        $this->publishQos1('t/a', 2, withUserProperty: true);

        $returning = $this->connect('sub', cleanSession: false);
        $first = $this->publishesOn($returning, ProtocolVersion::V311);
        self::assertCount(1, $first);
        self::assertNotNull($first[0]->packetId);
        $this->handler->handle($returning, new PubackPacket(packetId: $first[0]->packetId));

        // The second went out through the drain path, which used to encode it as the
        // 5.0 packet it was queued as: a 3.1.1 client read the property block as payload.
        $all = $this->publishesOn($returning, ProtocolVersion::V311);
        self::assertCount(2, $all);
        self::assertSame(['m1', 'm2'], [$all[0]->payload, $all[1]->payload]);
    }

    public function testAnAliasIsOnlyCommittedWithAPacketThatIsSent(): void
    {
        $subscriber = $this->v5Subscriber(maximumPacketSize: 60);
        $publisher = $this->connect('pub');

        // Too large for the client: dropped. The alias assigned to it used to stay
        // assigned, so the next message was sent as the alias alone - one the client
        // never received.
        $this->handler->handle($publisher, new PublishPacket(topicName: 't/a', payload: str_repeat('x', 100)));
        $this->handler->handle($publisher, new PublishPacket(topicName: 't/a', payload: 'small'));

        $delivered = $this->publishesOn($subscriber, ProtocolVersion::V50);
        self::assertCount(1, $delivered);
        self::assertSame('t/a', $delivered[0]->topicName);
    }

    private function v5Subscriber(int $receiveMaximum = 65535, int $maximumPacketSize = 0): Connection
    {
        $properties = new PropertyCollection();
        $properties->set(PropertyId::SessionExpiryInterval, 3600);
        $properties->set(PropertyId::TopicAliasMaximum, 10);
        $properties->set(PropertyId::ReceiveMaximum, $receiveMaximum);
        if ($maximumPacketSize > 0) {
            $properties->set(PropertyId::MaximumPacketSize, $maximumPacketSize);
        }

        $connection = $this->connect('sub', packet: new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V50->value,
            cleanSession: false,
            keepAlive: 60,
            clientId: 'sub',
            properties: $properties,
        ));

        if (!$this->subscriptions->hasSubscription('sub', 't/a')) {
            $this->handler->handle($connection, new SubscribePacket(
                packetId: 1,
                subscriptions: [['topic' => 't/a', 'qos' => 1]],
                protocolVersion: ProtocolVersion::V50,
            ));
        }

        return $connection;
    }

    private function publishQos1(string $topic, int $count, bool $withUserProperty = false): void
    {
        $publisher = $this->connect('pub');
        for ($i = 1; $i <= $count; $i++) {
            $properties = null;
            if ($withUserProperty) {
                $properties = new PropertyCollection();
                $properties->set(PropertyId::UserProperty, ['k', 'v']);
            }
            $this->handler->handle($publisher, new PublishPacket(
                topicName: $topic,
                payload: 'm' . $i,
                qos: 1,
                packetId: $i,
                protocolVersion: $withUserProperty ? ProtocolVersion::V50 : ProtocolVersion::V311,
                properties: $properties,
            ));
        }
        $this->handler->handleDisconnect($publisher, true);
        $this->connections->remove($publisher);
    }

    private function dropConnection(Connection $connection): void
    {
        $this->handler->handleDisconnect($connection, false);
        $this->connections->remove($connection);
    }

    /**
     * Acknowledge each PUBLISH as it arrives, resolving topic aliases the way a client
     * must: an alias is known only once a packet carrying it also named the topic.
     *
     * @return list<string> The topic of every message delivered, in order.
     */
    private function acknowledgeEverything(Connection $connection): array
    {
        $aliases = [];
        $topics = [];
        $handled = 0;

        while (($publishes = $this->publishesOn($connection, ProtocolVersion::V50)) !== [] && $handled < count($publishes)) {
            foreach (array_slice($publishes, $handled) as $publish) {
                $alias = $publish->properties?->get(PropertyId::TopicAlias);
                if ($publish->topicName !== '') {
                    if ($alias !== null) {
                        $aliases[$alias] = $publish->topicName;
                    }
                    $topics[] = $publish->topicName;
                } else {
                    self::assertArrayHasKey($alias, $aliases, 'Alias-only PUBLISH for an alias this connection never set');
                    $topics[] = $aliases[$alias];
                }

                $handled++;
                self::assertNotNull($publish->packetId);
                $this->handler->handle($connection, new PubackPacket(packetId: $publish->packetId, protocolVersion: ProtocolVersion::V50));
            }
        }

        return $topics;
    }

    /**
     * @return list<PublishPacket>
     */
    private function publishesOn(Connection $connection, ProtocolVersion $version): array
    {
        return array_values(array_filter(
            $this->sentPackets($connection, $version),
            static fn($packet): bool => $packet instanceof PublishPacket,
        ));
    }
}
