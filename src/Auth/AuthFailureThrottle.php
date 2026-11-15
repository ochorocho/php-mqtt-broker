<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

/**
 * Counts failed logins per remote host and refuses a host that fails too often.
 *
 * Every login attempt costs a password hash on the broker's only thread, so an
 * unthrottled guessing run is also a denial of service. A host over the budget is
 * answered without checking anything, until its failures age out of the window.
 * Successful logins are never counted, so a busy NAT'd fleet is not affected by its
 * own reconnects.
 */
final class AuthFailureThrottle
{
    /** @var array<string, list<float>> host => failure times, oldest first; insertion order is age */
    private array $failures = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param int $maxFailures Failures tolerated per host within the window; 0 disables.
     * @param float $window Seconds a failure counts for.
     * @param int $maxHosts Hosts tracked at once. The keys are whatever addresses
     *                      connect, so the table is bounded and the stalest host goes first.
     * @param (\Closure(): float)|null $clock Seconds, defaulting to microtime(true).
     */
    public function __construct(
        private readonly int $maxFailures,
        private readonly float $window,
        private readonly int $maxHosts = 10_000,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function isThrottled(string $host): bool
    {
        if ($this->maxFailures <= 0) {
            return false;
        }

        return count($this->recent($host)) >= $this->maxFailures;
    }

    public function recordFailure(string $host): void
    {
        if ($this->maxFailures <= 0) {
            return;
        }

        $recent = $this->recent($host);
        $recent[] = ($this->clock)();

        // Move the host to the end, so the first key is always the stalest one.
        unset($this->failures[$host]);
        $stalest = array_key_first($this->failures);
        if ($stalest !== null && count($this->failures) >= $this->maxHosts) {
            unset($this->failures[$stalest]);
        }

        // Only the newest maxFailures timestamps can ever matter.
        $this->failures[$host] = array_slice($recent, -$this->maxFailures);
    }

    public function trackedHosts(): int
    {
        return count($this->failures);
    }

    /**
     * The address part of a peer address: no scheme, no port, no IPv6 brackets.
     */
    public static function hostOf(string $remoteAddress): string
    {
        $address = preg_replace('#^[a-z0-9.+-]+://#i', '', $remoteAddress) ?? $remoteAddress;

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $address, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/^([^:]+):\d+$/', $address, $match) === 1) {
            return $match[1];
        }

        return $address;
    }

    /**
     * @return list<float>
     */
    private function recent(string $host): array
    {
        $cutoff = ($this->clock)() - $this->window;

        $recent = array_values(array_filter(
            $this->failures[$host] ?? [],
            static fn(float $at): bool => $at > $cutoff,
        ));

        if ($recent === []) {
            unset($this->failures[$host]);
        }

        return $recent;
    }
}
