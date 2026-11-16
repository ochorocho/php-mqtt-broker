<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Protocol\Packet\ConnackPacket;
use PhpMqtt\Broker\Protocol\Packet\SubscribePacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;

final class PacketHandlerSessionQuotaTest extends PacketHandlerTestCase
{
    public function testAnAccountAtItsQuotaWithEverySessionLiveIsRefused(): void
    {
        $this->makeHandler(config: new Configuration(maxSessionsPerUsername: 1));
        $first = $this->connect('alice-1', username: 'alice');

        $v311 = $this->connect('alice-2', username: 'alice');
        $v5 = $this->connect('alice-3', version: ProtocolVersion::V50, username: 'alice');

        self::assertTrue($first->isConnected(), 'The refusal must not cost the live session');
        self::assertFalse($v311->isConnected());
        self::assertSame(0x03, $this->lastSent($v311, ConnackPacket::class)->returnCode);
        self::assertSame(0x97, $this->lastSent($v5, ConnackPacket::class, ProtocolVersion::V50)->returnCode);
    }

    public function testReconnectingWithTheSameClientIdIsNotANewSession(): void
    {
        $this->makeHandler(config: new Configuration(maxSessionsPerUsername: 1));
        $this->connect('alice-1', username: 'alice');

        self::assertTrue($this->connect('alice-1', username: 'alice')->isConnected());
    }

    public function testAnAccountPaysForItsNewSessionWithItsOwnOldestOfflineOne(): void
    {
        $this->makeHandler(config: new Configuration(maxSessionsPerUsername: 1));
        $old = $this->connect('alice-1', cleanSession: false, username: 'alice');
        $this->handler->handle($old, new SubscribePacket(1, [['topic' => 'a', 'qos' => 1]]));
        $this->handler->handleDisconnect($old, false);
        $this->connections->remove($old);

        $new = $this->connect('alice-2', username: 'alice');

        self::assertTrue($new->isConnected());
        self::assertNull($this->handler->getSessionManager()->get('alice-1'));
        self::assertFalse($this->subscriptions->hasSubscription('alice-1', 'a'));
    }

    public function testOneAccountsChurnDoesNotEvictAnother(): void
    {
        $this->makeHandler(config: new Configuration(maxSessions: 3, maxSessionsPerUsername: 0));
        $bob = $this->connect('bob-1', cleanSession: false, username: 'bob');
        $this->handler->handleDisconnect($bob, false);
        $this->connections->remove($bob);

        // Alice cycles through client IDs with persistent sessions. When the table is
        // full, her own oldest offline session goes first, not bob's.
        for ($i = 1; $i <= 10; $i++) {
            $alice = $this->connect('alice-' . $i, cleanSession: false, username: 'alice');
            $this->handler->handleDisconnect($alice, false);
            $this->connections->remove($alice);
        }

        self::assertNotNull($this->handler->getSessionManager()->get('bob-1'));
    }
}
