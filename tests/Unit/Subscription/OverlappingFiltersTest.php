<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Subscription;

use PhpMqtt\Broker\Subscription\Subscription;
use PhpMqtt\Broker\Subscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;

/**
 * Shared subscriptions are matched by their filter without the `$share/<group>/`
 * prefix, so one client's `a/b`, `$share/g/a/b` and `$share/h/a/b` all land on the same
 * routing node. Keyed by client ID alone, each overwrote the last, and unsubscribing
 * any one of them silently ended the others.
 */
final class OverlappingFiltersTest extends TestCase
{
    public function testAPlainAndASharedSubscriptionOnOneFilterBothMatch(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('c', 'a/b', 0);
        $manager->subscribe('c', '$share/g/a/b', 1);

        self::assertSame(['$share/g/a/b', 'a/b'], $this->filtersMatching($manager, 'a/b'));
    }

    public function testUnsubscribingTheSharedOneLeavesThePlainOne(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('c', 'a/b', 0);
        $manager->subscribe('c', '$share/g/a/b', 1);

        $manager->unsubscribe('c', '$share/g/a/b');

        self::assertSame(['a/b'], $this->filtersMatching($manager, 'a/b'));
        self::assertTrue($manager->hasSubscription('c', 'a/b'));
    }

    public function testTwoGroupsOnOneFilterAreIndependent(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('c', '$share/g/a/b', 0);
        $manager->subscribe('c', '$share/h/a/b', 0);

        $manager->unsubscribe('c', '$share/g/a/b');

        self::assertSame(['$share/h/a/b'], $this->filtersMatching($manager, 'a/b'));
    }

    public function testRemovingTheClientRemovesEveryOne(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('c', 'a/b', 0);
        $manager->subscribe('c', '$share/g/a/b', 0);
        $manager->subscribe('other', 'a/b', 0);

        $manager->removeClient('c');

        self::assertSame(['a/b'], $this->filtersMatching($manager, 'a/b'));
        self::assertSame('other', $manager->getMatchingSubscriptions('a/b')[0]->clientId);
    }

    public function testResubscribingReplacesRatherThanDuplicates(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('c', '$share/g/a/b', 0);
        $manager->subscribe('c', '$share/g/a/b', 2);

        $matches = $manager->getMatchingSubscriptions('a/b');
        self::assertCount(1, $matches);
        self::assertSame(2, $matches[0]->qos);
    }

    /**
     * @return list<string>
     */
    private function filtersMatching(SubscriptionManager $manager, string $topic): array
    {
        $filters = array_map(
            static fn(Subscription $sub): string => $sub->topicFilter,
            $manager->getMatchingSubscriptions($topic),
        );
        sort($filters);

        return $filters;
    }
}
