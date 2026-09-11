<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\ConnectPacket;
use PhpMqtt\Broker\Protocol\Packet\DisconnectPacket;
use PhpMqtt\Broker\Protocol\Packet\PingrespPacket;
use PhpMqtt\Broker\Protocol\Packet\PingreqPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\Packet\UnsubscribePacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

/**
 * Connection and session lifecycle: what survives a disconnect, what does not, and
 * what an unauthenticated or misbehaving peer is allowed to consume.
 */
final class PacketHandlerLifecycleTest extends PacketHandlerTestCase
{
    public function testSuccessfulConnectIsAcknowledgedAndMarksConnected(): void
    {
        $connection = $this->connect('client');

        self::assertTrue($connection->isConnected());
        self::assertSame('client', $connection->getClientId());

        $connack = $this->lastSent($connection, ConnackPacket::class);
        self::assertSame(0x00, $connack->returnCode);
        self::assertFalse($connack->sessionPresent);
    }

    public function testFirstPacketMustBeConnect(): void
    {
        $connection = $this->connection();

        $this->expectException(\PhpMqtt\Broker\Exception\ProtocolViolationException::class);
        $this->handler->handle($connection, new PingreqPacket());
    }

    public function testSecondConnectIsAProtocolViolation(): void
    {
        $connection = $this->connect('client');

        $this->expectException(\PhpMqtt\Broker\Exception\ProtocolViolationException::class);
        $this->handler->handle($connection, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V311->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'client',
        ));
    }

    public function testPingIsAnswered(): void
    {
        $connection = $this->connect('client');
        $this->handler->handle($connection, new PingreqPacket());

        self::assertSame(1, $this->countSent($connection, PingrespPacket::class));
    }

    public function testCleanSessionLeavesNothingBehind(): void
    {
        $connection = $this->connect('transient', cleanSession: true);
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 0]]));

        $this->handler->handleDisconnect($connection, true);

        self::assertNull($this->handler->getSessionManager()->get('transient'));
        self::assertSame(0, $this->subscriptions->countClientSubscriptions('transient'));
    }

    public function testPersistentSessionKeepsSubscriptionsAndIsReportedOnReconnect(): void
    {
        $first = $this->connect('persistent', cleanSession: false);
        $this->handler->handle($first, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 1]]));
        $this->handler->handleDisconnect($first, true);
        $this->connections->remove($first);

        $session = $this->handler->getSessionManager()->get('persistent');
        self::assertNotNull($session);
        self::assertCount(1, $session->subscriptions);

        $second = $this->connect('persistent', cleanSession: false);
        self::assertTrue($this->lastSent($second, ConnackPacket::class)->sessionPresent);
        self::assertTrue($this->subscriptions->hasSubscription('persistent', 'a/b'));
    }

    public function testUnsubscribeRemovesTheSubscription(): void
    {
        $connection = $this->connect('client');
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 0]]));
        self::assertTrue($this->subscriptions->hasSubscription('client', 'a/b'));

        $this->handler->handle($connection, new UnsubscribePacket(2, ['a/b']));

        self::assertFalse($this->subscriptions->hasSubscription('client', 'a/b'));
    }

    public function testConnectingWithTheSameClientIdEvictsTheOlderConnection(): void
    {
        $first = $this->connect('shared-id');
        self::assertTrue($first->isConnected());

        $second = $this->connect('shared-id');

        self::assertTrue($second->isConnected());
        self::assertFalse($first->isConnected(), 'The displaced connection must be torn down');
        self::assertSame($second, $this->connections->getByClientId('shared-id'));
    }

    public function testAbnormalDisconnectPublishesTheWill(): void
    {
        $watcher = $this->connect('watcher');
        $this->handler->handle($watcher, new SubscribePacket(1, [['topic' => 'wills', 'qos' => 0]]));
        $before = $this->countSent($watcher, PublishPacket::class);

        $willer = $this->connection();
        $this->handler->handle($willer, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V311->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'willer',
            hasWill: true,
            willTopic: 'wills',
            willPayload: 'gone',
        ));
        $this->connections->register('willer', $willer);

        $this->handler->handleDisconnect($willer, false);

        self::assertSame($before + 1, $this->countSent($watcher, PublishPacket::class));
        self::assertSame('gone', $this->lastSent($watcher, PublishPacket::class)->payload);
    }

    public function testCleanDisconnectSuppressesTheWill(): void
    {
        $watcher = $this->connect('watcher');
        $this->handler->handle($watcher, new SubscribePacket(1, [['topic' => 'wills', 'qos' => 0]]));
        $before = $this->countSent($watcher, PublishPacket::class);

        $willer = $this->connection();
        $this->handler->handle($willer, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V311->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'willer',
            hasWill: true,
            willTopic: 'wills',
            willPayload: 'gone',
        ));
        $this->connections->register('willer', $willer);

        // A DISCONNECT packet is a graceful goodbye: the will must not fire.
        $this->handler->handle($willer, new DisconnectPacket());

        self::assertSame($before, $this->countSent($watcher, PublishPacket::class));
    }

    public function testTeardownRunsOnceEvenWhenTriggeredTwice(): void
    {
        $watcher = $this->connect('watcher');
        $this->handler->handle($watcher, new SubscribePacket(1, [['topic' => 'wills', 'qos' => 0]]));
        $before = $this->countSent($watcher, PublishPacket::class);

        $willer = $this->connection();
        $this->handler->handle($willer, new ConnectPacket(
            protocolName: 'MQTT',
            protocolLevel: ProtocolVersion::V311->value,
            cleanSession: true,
            keepAlive: 60,
            clientId: 'willer',
            hasWill: true,
            willTopic: 'wills',
            willPayload: 'gone',
        ));
        $this->connections->register('willer', $willer);

        // The keepalive timer and the socket close can both reach this.
        $this->handler->handleDisconnect($willer, false);
        $this->handler->handleDisconnect($willer, false);

        self::assertSame(
            $before + 1,
            $this->countSent($watcher, PublishPacket::class),
            'The will must be published exactly once',
        );
    }

    public function testTeardownStillRunsAfterTheReceiveMaximumPathStopsTheConnection(): void
    {
        // Exceeding the server receive maximum marks the connection not-connected and
        // defers the close. Teardown has to survive that: guarding it on "is the
        // connection live" meant the later socket close skipped cleanup entirely,
        // leaving the session unsaved and per-client state stranded.
        // 3.1.1 deliberately: a 5.0 client that sends no Session Expiry Interval has a
        // session that expires the moment it disconnects, so there would be nothing
        // left to observe. The receive-maximum path itself is the same either way.
        $client = $this->connect('flooder', cleanSession: false);
        $this->handler->handle($client, new SubscribePacket(1, [['topic' => 'a/b', 'qos' => 1]]));

        // Send un-released QoS 2 messages until the broker stops the connection. A real
        // client's next packet would arrive after the close, so stop pushing once the
        // broker has decided it has had enough.
        for ($i = 1; $i <= 25 && $client->isConnected(); $i++) {
            $this->handler->handle($client, new PublishPacket(
                topicName: 'a/b',
                payload: 'x',
                qos: 2,
                packetId: $i,
            ));
        }

        self::assertFalse($client->isConnected(), 'The flood should have stopped the connection');
        self::assertFalse(
            $client->isDisconnectHandled(),
            'Stopping the connection is not the same as having torn it down',
        );

        // Now the socket actually closes, the way Broker::onClose would drive it.
        $this->handler->handleDisconnect($client, false);

        self::assertTrue($client->isDisconnectHandled());

        $session = $this->handler->getSessionManager()->get('flooder');
        self::assertNotNull($session, 'The persistent session must still be saved');
        self::assertCount(1, $session->subscriptions);
    }

    public function testMessagesArriveOnReconnectAfterBeingQueuedOffline(): void
    {
        $subscriber = $this->connect('offline-sub', cleanSession: false);
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'news', 'qos' => 1]]));
        $this->handler->handleDisconnect($subscriber, true);
        $this->connections->remove($subscriber);

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(
            topicName: 'news',
            payload: 'while-away',
            qos: 1,
            packetId: 5,
        ));

        $session = $this->handler->getSessionManager()->get('offline-sub');
        self::assertNotNull($session);
        self::assertCount(1, $session->pendingMessages);

        $reconnected = $this->connect('offline-sub', cleanSession: false);

        $delivered = array_filter(
            $this->sentPackets($reconnected),
            static fn($packet): bool => $packet instanceof PublishPacket,
        );
        self::assertCount(1, $delivered);
        self::assertSame('while-away', array_values($delivered)[0]->payload);
    }

    public function testOversizedClientIdIsRejected(): void
    {
        $this->makeHandler(null, new Configuration(maxClientIdLength: 8));

        $connection = $this->connect(str_repeat('x', 64));

        self::assertFalse($connection->isConnected());
        self::assertTrue($this->stream($connection)->closed);
    }

    public function testSubscriptionsPerClientAreCapped(): void
    {
        $this->makeHandler(null, new Configuration(maxSubscriptionsPerClient: 2));

        $connection = $this->connect('greedy');
        $this->handler->handle($connection, new SubscribePacket(1, [
            ['topic' => 'a/1', 'qos' => 0],
            ['topic' => 'a/2', 'qos' => 0],
            ['topic' => 'a/3', 'qos' => 0],
        ]));

        self::assertSame([0x00, 0x00, 0x80], $this->lastSent($connection, SubackPacket::class)->returnCodes);
        self::assertSame(2, $this->subscriptions->countClientSubscriptions('greedy'));
    }

    public function testDeepTopicFiltersAreRefused(): void
    {
        $this->makeHandler(null, new Configuration(maxTopicLevels: 3));

        $connection = $this->connect('client');
        $this->handler->handle($connection, new SubscribePacket(1, [
            ['topic' => 'a/b/c', 'qos' => 0],
            ['topic' => 'a/b/c/d/e', 'qos' => 0],
        ]));

        self::assertSame([0x00, 0x80], $this->lastSent($connection, SubackPacket::class)->returnCodes);
    }

    public function testOfflineQueueDropsTheOldestWhenFull(): void
    {
        $this->makeHandler(null, new Configuration(maxPendingMessagesPerSession: 2));

        $subscriber = $this->connect('offline-sub', cleanSession: false);
        $this->handler->handle($subscriber, new SubscribePacket(1, [['topic' => 'flood', 'qos' => 1]]));
        $this->handler->handleDisconnect($subscriber, true);
        $this->connections->remove($subscriber);

        $publisher = $this->connect('publisher');
        for ($i = 1; $i <= 5; $i++) {
            $this->handler->handle($publisher, new PublishPacket(
                topicName: 'flood',
                payload: 'm' . $i,
                qos: 1,
                packetId: $i,
            ));
        }

        $session = $this->handler->getSessionManager()->get('offline-sub');
        self::assertNotNull($session);
        self::assertCount(2, $session->pendingMessages);

        // The newest messages are the ones worth keeping.
        $payloads = array_map(static fn($packet): string => $packet->payload, $session->pendingMessages);
        self::assertSame(['m4', 'm5'], $payloads);
    }
}
