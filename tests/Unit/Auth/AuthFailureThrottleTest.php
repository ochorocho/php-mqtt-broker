<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Auth;

use PhpMqtt\Broker\Auth\AuthFailureThrottle;
use PHPUnit\Framework\TestCase;

final class AuthFailureThrottleTest extends TestCase
{
    private float $now = 1000.0;

    private function throttle(int $maxFailures = 3, float $window = 60.0, int $maxHosts = 10_000): AuthFailureThrottle
    {
        return new AuthFailureThrottle(
            maxFailures: $maxFailures,
            window: $window,
            maxHosts: $maxHosts,
            clock: fn(): float => $this->now,
        );
    }

    public function testAHostIsAllowedUntilItReachesTheLimit(): void
    {
        $throttle = $this->throttle(maxFailures: 3);

        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($throttle->isThrottled('203.0.113.9'));
            $throttle->recordFailure('203.0.113.9');
        }

        self::assertTrue($throttle->isThrottled('203.0.113.9'));
    }

    public function testFailuresOutsideTheWindowNoLongerCount(): void
    {
        $throttle = $this->throttle(maxFailures: 2, window: 60.0);
        $throttle->recordFailure('203.0.113.9');
        $throttle->recordFailure('203.0.113.9');
        self::assertTrue($throttle->isThrottled('203.0.113.9'));

        $this->now += 61.0;

        self::assertFalse($throttle->isThrottled('203.0.113.9'));
    }

    public function testHostsAreCountedSeparately(): void
    {
        $throttle = $this->throttle(maxFailures: 1);
        $throttle->recordFailure('203.0.113.9');

        self::assertTrue($throttle->isThrottled('203.0.113.9'));
        self::assertFalse($throttle->isThrottled('198.51.100.1'));
    }

    public function testTheTableOfHostsIsBounded(): void
    {
        $throttle = $this->throttle(maxFailures: 1, maxHosts: 2);
        $throttle->recordFailure('10.0.0.1');
        $throttle->recordFailure('10.0.0.2');
        $throttle->recordFailure('10.0.0.3');

        // Keys are attacker-chosen addresses; the oldest is forgotten first.
        self::assertSame(2, $throttle->trackedHosts());
        self::assertFalse($throttle->isThrottled('10.0.0.1'));
        self::assertTrue($throttle->isThrottled('10.0.0.3'));
    }

    public function testDisabledWithALimitOfZero(): void
    {
        $throttle = $this->throttle(maxFailures: 0);
        $throttle->recordFailure('203.0.113.9');

        self::assertFalse($throttle->isThrottled('203.0.113.9'));
        self::assertSame(0, $throttle->trackedHosts());
    }

    public function testHostIsTheAddressWithoutSchemeOrPort(): void
    {
        self::assertSame('203.0.113.9', AuthFailureThrottle::hostOf('tcp://203.0.113.9:51234'));
        self::assertSame('2001:db8::1', AuthFailureThrottle::hostOf('tls://[2001:db8::1]:8883'));
        self::assertSame('203.0.113.9', AuthFailureThrottle::hostOf('203.0.113.9:51234'));
        self::assertSame('unknown', AuthFailureThrottle::hostOf('unknown'));
    }
}
