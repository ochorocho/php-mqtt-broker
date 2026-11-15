<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Session;

final class SessionManager
{
    /** @var array<string, Session> */
    private array $sessions = [];

    /**
     * @param (\Closure(string): void)|null $onRemoved Told the client ID of every session
     *        that expires or is evicted, so state kept elsewhere - subscriptions above
     *        all - goes with it. Not called for destroy(), whose callers clean up.
     */
    public function __construct(
        private readonly int $maxSessions = 10000,
        private readonly ?\Closure $onRemoved = null,
    ) {
    }

    /**
     * Drop every session whose expiry has passed.
     *
     * Expiry was previously only evaluated inside get(), so a session nobody ever
     * looked up again was never reclaimed: connect with a fresh client ID, disconnect,
     * repeat, and the broker leaked until it ran out of memory. Call this periodically.
     *
     * @return int Number of sessions reaped.
     */
    public function reapExpired(): int
    {
        $now = microtime(true);
        $reaped = 0;

        foreach ($this->sessions as $clientId => $session) {
            if ($session->disconnectedAt <= 0.0) {
                continue;
            }

            if ($now - $session->disconnectedAt >= $session->sessionExpiryInterval) {
                $this->remove((string) $clientId);
                $reaped++;
            }
        }

        return $reaped;
    }

    public function count(): int
    {
        return count($this->sessions);
    }

    /**
     * Evict the session idle the longest, so a flood of new client IDs cannot grow
     * the table without bound between reaper runs.
     */
    private function evictOldestDisconnected(): void
    {
        $oldestId = null;
        $oldestAt = INF;

        foreach ($this->sessions as $clientId => $session) {
            if ($session->disconnectedAt > 0.0 && $session->disconnectedAt < $oldestAt) {
                $oldestAt = $session->disconnectedAt;
                $oldestId = $clientId;
            }
        }

        if ($oldestId !== null) {
            $this->remove((string) $oldestId);
        }
    }

    public function get(string $clientId): ?Session
    {
        $session = $this->sessions[$clientId] ?? null;

        // Check if session has expired
        if ($session !== null && $session->disconnectedAt > 0.0 && $session->sessionExpiryInterval > 0) {
            $elapsed = microtime(true) - $session->disconnectedAt;
            if ($elapsed >= $session->sessionExpiryInterval) {
                $this->remove($clientId);
                return null;
            }
        }

        // SessionExpiryInterval=0 means session expires immediately on disconnect
        if ($session !== null && $session->disconnectedAt > 0.0 && $session->sessionExpiryInterval === 0) {
            $this->remove($clientId);
            return null;
        }

        return $session;
    }

    public function getOrCreate(string $clientId): Session
    {
        if (!isset($this->sessions[$clientId])) {
            if (count($this->sessions) >= $this->maxSessions) {
                $this->reapExpired();
            }
            if (count($this->sessions) >= $this->maxSessions) {
                $this->evictOldestDisconnected();
            }

            $this->sessions[$clientId] = new Session($clientId);
        }

        return $this->sessions[$clientId];
    }

    public function destroy(string $clientId): void
    {
        unset($this->sessions[$clientId]);
    }

    /**
     * A session ending on its own, by expiry or eviction.
     */
    private function remove(string $clientId): void
    {
        unset($this->sessions[$clientId]);

        if ($this->onRemoved !== null) {
            ($this->onRemoved)($clientId);
        }
    }
}
