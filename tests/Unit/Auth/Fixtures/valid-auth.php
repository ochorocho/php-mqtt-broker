<?php

declare(strict_types=1);

use PhpMqtt\Broker\Auth\AuthenticatorInterface;

return new class implements AuthenticatorInterface {
    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        return $username === 'fixture';
    }

    public function canSubscribe(string $clientId, string $topicFilter): bool
    {
        return true;
    }

    public function canPublish(string $clientId, string $topicName): bool
    {
        return true;
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        return true;
    }
};
