<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Session;

final class SessionManager
{
    /** @var array<string, Session> */
    private array $sessions = [];

    /** @var array<string, array<string, true>> username => client IDs of its sessions */
    private array $byUsername = [];

    /**
     * @param (\Closure(string): void)|null $onRemoved Told the client ID of every session
     *        that expires or is evicted, so state kept elsewhere - subscriptions above
     *        all - goes with it. Not called for destroy(), whose callers clean up.
     * @param int $maxSessionsPerUsername Sessions, live or offline, one account may hold.
     *        0 disables. Sessions without a username count against $maxSessions only.
     */
    public function __construct(
        private readonly int $maxSessions = 10000,
        private readonly ?\Closure $onRemoved = null,
        private readonly int $maxSessionsPerUsername = 0,
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
     * Evict the session idle the longest, among $candidates (client IDs) or among all,
     * so a flood of new client IDs cannot grow the table without bound.
     *
     * @param ?array<string, true> $candidates
     * @return bool Whether a session was evicted.
     */
    private function evictOldestDisconnected(?array $candidates = null, ?string $except = null): bool
    {
        $oldestId = null;
        $oldestAt = INF;

        foreach ($candidates === null ? array_keys($this->sessions) : array_keys($candidates) as $clientId) {
            $session = $this->sessions[(string) $clientId] ?? null;
            if ($session === null || (string) $clientId === $except) {
                continue;
            }
            if ($session->disconnectedAt > 0.0 && $session->disconnectedAt < $oldestAt) {
                $oldestAt = $session->disconnectedAt;
                $oldestId = (string) $clientId;
            }
        }

        if ($oldestId === null) {
            return false;
        }

        $this->remove($oldestId);

        return true;
    }

    /**
     * Whether a new session for this client would take its account past the quota with
     * nothing to make room: every session the account holds is live. The broker refuses
     * such a CONNECT, rather than evict a live session or let one account grow without bound.
     */
    public function isOverQuota(string $clientId, ?string $username): bool
    {
        if ($username === null || $this->maxSessionsPerUsername <= 0 || isset($this->sessions[$clientId])) {
            return false;
        }

        $owned = $this->ownedBy($username);
        if (count($owned) < $this->maxSessionsPerUsername) {
            return false;
        }

        foreach (array_keys($owned) as $ownedId) {
            if (($this->sessions[(string) $ownedId]->disconnectedAt ?? 0.0) > 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The session for a connecting client, created if need be and recorded against its
     * account.
     *
     * Eviction used to be one global queue: the oldest offline session went, whoever it
     * belonged to, so one account churning through client IDs could push out every
     * other account's sessions and their queued messages. An account now pays for its
     * own sessions first - past its quota, and when the whole table is full.
     */
    public function claim(string $clientId, ?string $username): Session
    {
        $existing = $this->sessions[$clientId] ?? null;
        if ($existing === null && $username !== null && count($this->sessions) >= $this->maxSessions) {
            $this->reapExpired();
            if (count($this->sessions) >= $this->maxSessions) {
                $this->evictOldestDisconnected($this->ownedBy($username), $clientId);
            }
        }

        $session = $this->getOrCreate($clientId);
        $this->assignUsername($session, $username);

        if ($username !== null && $this->maxSessionsPerUsername > 0) {
            while (count($this->ownedBy($username)) > $this->maxSessionsPerUsername
                && $this->evictOldestDisconnected($this->ownedBy($username), $clientId)
            ) {
                // Each pass removes this account's oldest offline session.
            }
        }

        return $session;
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
        $this->forgetUsername($clientId);
        unset($this->sessions[$clientId]);
    }

    /**
     * Client IDs of the sessions an account holds. Evicting one can drop the account's
     * entry altogether, so this is read afresh rather than kept.
     *
     * @return array<string, true>
     */
    private function ownedBy(string $username): array
    {
        return $this->byUsername[$username] ?? [];
    }

    private function assignUsername(Session $session, ?string $username): void
    {
        if ($session->username === $username) {
            if ($username !== null) {
                $this->byUsername[$username][$session->clientId] = true;
            }
            return;
        }

        $this->forgetUsername($session->clientId);
        $session->username = $username;
        if ($username !== null) {
            $this->byUsername[$username][$session->clientId] = true;
        }
    }

    private function forgetUsername(string $clientId): void
    {
        $username = $this->sessions[$clientId]->username ?? null;
        if ($username === null) {
            return;
        }

        unset($this->byUsername[$username][$clientId]);
        if (($this->byUsername[$username] ?? []) === []) {
            unset($this->byUsername[$username]);
        }
    }

    /**
     * A session ending on its own, by expiry or eviction.
     */
    private function remove(string $clientId): void
    {
        $this->forgetUsername($clientId);
        unset($this->sessions[$clientId]);

        if ($this->onRemoved !== null) {
            ($this->onRemoved)($clientId);
        }
    }
}
