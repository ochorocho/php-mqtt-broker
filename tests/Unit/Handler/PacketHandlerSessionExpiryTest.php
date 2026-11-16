<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;

/**
 * A session that ends - by expiry or by eviction - must take its routing with it.
 *
 * Sessions used to be dropped on their own, leaving their subscriptions in the routing
 * table for good: memory that only grew, and messages for a client that had no session,
 * delivered under grants that were never checked again.
 */
final class PacketHandlerSessionExpiryTest extends PacketHandlerTestCase
{
    public function testAReapedSessionsSubscriptionsStopMatching(): void
    {
        $this->persistentClientSubscribedTo('gone', 'a/b');
        $this->age('gone', 100_000);

        $this->handler->getSessionManager()->reapExpired();

        self::assertSame([], $this->subscriptions->getMatchingSubscriptions('a/b'));
    }

    public function testAnEvictedSessionTakesItsSubscriptionsWithIt(): void
    {
        $this->makeHandler(config: new Configuration(maxSessions: 1));
        $this->persistentClientSubscribedTo('old', 'a/b');

        // A new client needs a session slot; the only one belongs to 'old', offline.
        $this->connect('new');

        self::assertNull($this->handler->getSessionManager()->get('old'));
        self::assertFalse($this->subscriptions->hasSubscription('old', 'a/b'));
    }

    public function testReconnectingAfterExpiryRestoresNoSubscriptions(): void
    {
        $authenticator = new RecordingAuthenticator();
        $this->makeHandler($authenticator);
        $this->persistentClientSubscribedTo('device', 'secret/#');
        $this->age('device', 100_000);

        // Access revoked while the client was away. Re-authorization only ran when the
        // session still existed, so after expiry the old grant came straight back.
        $authenticator->deniedSubscribeFilters = ['secret/#'];
        $returning = $this->connect('device', cleanSession: false);
        self::assertFalse($this->lastSent($returning, ConnackPacket::class)->sessionPresent);

        $publisher = $this->connect('publisher');
        $this->handler->handle($publisher, new PublishPacket(topicName: 'secret/plans', payload: 'x'));

        self::assertSame(0, $this->countSent($returning, PublishPacket::class));
    }

    public function testAConnectedClientsSessionSurvivesTheReaper(): void
    {
        $this->makeHandler(config: new Configuration(maxSessionExpiry: 2));
        $this->persistentClientSubscribedTo('live', 'a/b');

        // Offline for most of its expiry, then back just in time.
        $this->age('live', 1.5);
        $connection = $this->connect('live', cleanSession: false);
        self::assertTrue($this->lastSent($connection, ConnackPacket::class)->sessionPresent);

        // The reaper measures from disconnectedAt. A reconnect left the old value in
        // place, so a session counted as expiring while its client was connected.
        usleep(700_000);
        $this->handler->getSessionManager()->reapExpired();

        self::assertNotNull($this->handler->getSessionManager()->get('live'));
        self::assertTrue($this->subscriptions->hasSubscription('live', 'a/b'));
    }

    private function persistentClientSubscribedTo(string $clientId, string $filter): Connection
    {
        $connection = $this->connect($clientId, cleanSession: false);
        $this->handler->handle($connection, new SubscribePacket(1, [['topic' => $filter, 'qos' => 1]]));
        $this->handler->handleDisconnect($connection, true);
        $this->connections->remove($connection);

        return $connection;
    }

    /**
     * Pretend the client disconnected this many seconds ago.
     */
    private function age(string $clientId, float $seconds): void
    {
        $session = $this->handler->getSessionManager()->getOrCreate($clientId);
        $session->disconnectedAt = microtime(true) - $seconds;
    }
}
