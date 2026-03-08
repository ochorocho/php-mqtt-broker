<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

final class AllowAllAuthenticator implements AuthenticatorInterface
{
    /** @var string[] Topic filters that are denied for subscription */
    private array $noSubscribeTopics;

    /**
     * @param string[] $noSubscribeTopics Topic filters that are denied for subscription
     */
    public function __construct(array $noSubscribeTopics = ['test/nosubscribe'])
    {
        $this->noSubscribeTopics = $noSubscribeTopics;
    }

    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        return true;
    }

    public function canSubscribe(string $clientId, string $topicFilter): bool
    {
        return !in_array($topicFilter, $this->noSubscribeTopics, true);
    }

    public function canPublish(string $clientId, string $topicName): bool
    {
        return true;
    }
}
