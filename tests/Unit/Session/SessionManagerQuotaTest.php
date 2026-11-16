<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Session;

use PhpMqtt\Broker\Session\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Eviction used to be one global queue: the oldest offline session went, whoever it
 * belonged to. Any account able to create sessions could churn through IDs and push
 * every other account's offline sessions - and their queued messages - out.
 */
final class SessionManagerQuotaTest extends TestCase
{
    /** @var list<string> */
    private array $removed = [];

    private function manager(int $maxSessions = 100, int $perUsername = 0): SessionManager
    {
        return new SessionManager(
            maxSessions: $maxSessions,
            onRemoved: function (string $clientId): void {
                $this->removed[] = $clientId;
            },
            maxSessionsPerUsername: $perUsername,
        );
    }

    public function testAnAccountOverItsQuotaLosesItsOwnOldestOfflineSession(): void
    {
        $manager = $this->manager(perUsername: 2);
        $this->offline($manager, 'bob-1', 'bob', 40);
        $this->offline($manager, 'alice-1', 'alice', 30);
        $this->offline($manager, 'alice-2', 'alice', 20);

        $manager->claim('alice-3', 'alice');

        self::assertSame(['alice-1'], $this->removed);
        self::assertNotNull($manager->get('bob-1'), 'Another account is not touched');
    }

    public function testWhenTheTableIsFullTheClaimingAccountPaysFirst(): void
    {
        $manager = $this->manager(maxSessions: 3);
        $this->offline($manager, 'bob-1', 'bob', 40);
        $this->offline($manager, 'bob-2', 'bob', 30);
        $this->offline($manager, 'alice-1', 'alice', 10);

        $manager->claim('alice-2', 'alice');

        self::assertSame(['alice-1'], $this->removed);
    }

    public function testAnAccountWhoseSessionsAreAllLiveIsOverQuota(): void
    {
        $manager = $this->manager(perUsername: 1);
        $manager->claim('alice-1', 'alice');

        self::assertTrue($manager->isOverQuota('alice-2', 'alice'));
        self::assertFalse($manager->isOverQuota('alice-1', 'alice'), 'Reconnecting to its own session is not a new one');
        self::assertFalse($manager->isOverQuota('bob-1', 'bob'));
        self::assertFalse($manager->isOverQuota('anon', null), 'Anonymous sessions have no account to charge');
    }

    public function testAnOfflineSessionMakesRoomInsteadOfARefusal(): void
    {
        $manager = $this->manager(perUsername: 1);
        $this->offline($manager, 'alice-1', 'alice', 10);

        self::assertFalse($manager->isOverQuota('alice-2', 'alice'));
    }

    public function testAnEndedSessionGivesItsSlotBack(): void
    {
        $manager = $this->manager(perUsername: 1);
        $manager->claim('alice-1', 'alice');
        $manager->destroy('alice-1');

        self::assertFalse($manager->isOverQuota('alice-2', 'alice'));
    }

    public function testASessionMovesWithItsUsername(): void
    {
        $manager = $this->manager(perUsername: 1);
        $manager->claim('shared-id', 'alice');
        $manager->claim('shared-id', 'bob');

        self::assertFalse($manager->isOverQuota('alice-2', 'alice'));
        self::assertTrue($manager->isOverQuota('bob-2', 'bob'));
    }

    private function offline(SessionManager $manager, string $clientId, string $username, float $secondsAgo): void
    {
        $session = $manager->claim($clientId, $username);
        $session->sessionExpiryInterval = 3600;
        $session->disconnectedAt = microtime(true) - $secondsAgo;
    }
}
