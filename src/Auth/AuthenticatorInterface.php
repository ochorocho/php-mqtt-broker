<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

interface AuthenticatorInterface
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool;

    /**
     * Check if a topic is allowed for subscription.
     */
    public function canSubscribe(string $clientId, string $topicFilter): bool;

    /**
     * Check if a topic is allowed for publishing.
     */
    public function canPublish(string $clientId, string $topicName): bool;

    /**
     * Check whether the authenticated credentials may use this client ID.
     *
     * Connecting with another client's ID evicts that client and, for persistent
     * sessions, inherits its subscriptions and queued messages. Bind the client ID
     * to the authenticated principal here to prevent takeover of another identity.
     */
    public function canUseClientId(string $clientId, ?string $username): bool;
}
