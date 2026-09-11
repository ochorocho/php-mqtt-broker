<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Subscription;

use PhpMqtt\Broker\Subscription\SubscriptionManager;
use PHPUnit\Framework\TestCase;

/**
 * Client identifiers are chosen by the client and are frequently all digits.
 *
 * PHP silently turns a numeric-string array key into an integer, so a client ID read
 * back out of an array key arrives as int(123) rather than "123". Routing used to
 * depend on a cast at the call site to undo that.
 */
final class SubscriptionKeyTest extends TestCase
{
    public function testClientIdStaysAStringOnTheSubscription(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('123', 'a/b', 0);

        $matches = $manager->getMatchingSubscriptions('a/b');

        self::assertCount(1, $matches);
        self::assertSame('123', $matches[0]->clientId);
        self::assertIsString($matches[0]->clientId);
    }

    public function testNumericAndLeadingZeroClientIdsStayDistinct(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('123', 'a/b', 0);
        $manager->subscribe('0123', 'a/b', 0);
        $manager->subscribe('alice', 'a/b', 0);

        $clientIds = array_map(
            static fn($sub): string => $sub->clientId,
            $manager->getMatchingSubscriptions('a/b'),
        );
        // SORT_STRING matters here: the default comparison treats '123' and '0123' as
        // equal numbers, which is the very coercion this test exists to rule out.
        sort($clientIds, SORT_STRING);

        self::assertSame(['0123', '123', 'alice'], $clientIds);
    }

    public function testNumericClientIdCanBeRemovedIndividually(): void
    {
        $manager = new SubscriptionManager();
        $manager->subscribe('123', 'a/b', 0);
        $manager->subscribe('456', 'a/b', 0);

        $manager->removeClient('123');

        $clientIds = array_map(
            static fn($sub): string => $sub->clientId,
            $manager->getMatchingSubscriptions('a/b'),
        );

        self::assertSame(['456'], $clientIds);
        self::assertSame(0, $manager->countClientSubscriptions('123'));
    }
}
