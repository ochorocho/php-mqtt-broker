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
}
