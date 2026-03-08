<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Session;

final class SessionManager
{
    /** @var array<string, Session> */
    private array $sessions = [];

    public function get(string $clientId): ?Session
    {
        $session = $this->sessions[$clientId] ?? null;

        // Check if session has expired
        if ($session !== null && $session->disconnectedAt > 0.0 && $session->sessionExpiryInterval > 0) {
            $elapsed = microtime(true) - $session->disconnectedAt;
            if ($elapsed >= $session->sessionExpiryInterval) {
                unset($this->sessions[$clientId]);
                return null;
            }
        }

        // SessionExpiryInterval=0 means session expires immediately on disconnect
        if ($session !== null && $session->disconnectedAt > 0.0 && $session->sessionExpiryInterval === 0) {
            unset($this->sessions[$clientId]);
            return null;
        }

        return $session;
    }

    public function getOrCreate(string $clientId): Session
    {
        if (!isset($this->sessions[$clientId])) {
            $this->sessions[$clientId] = new Session($clientId);
        }

        return $this->sessions[$clientId];
    }

    public function destroy(string $clientId): void
    {
        unset($this->sessions[$clientId]);
    }

    public function exists(string $clientId): bool
    {
        return isset($this->sessions[$clientId]);
    }
}
