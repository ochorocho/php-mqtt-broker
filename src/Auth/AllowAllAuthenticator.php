<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

use PhpMqtt\Broker\Protocol\TopicFilter;

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

    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool
    {
        // '$share/<group>/' changes who receives a message, not which messages: compare
        // the filter behind it, or the prefix walks straight past the list.
        return !in_array(TopicFilter::stripSharedPrefix($topicFilter), $this->noSubscribeTopics, true);
    }

    public function canPublish(string $clientId, ?string $username, string $topicName): bool
    {
        return true;
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        return true;
    }
}
