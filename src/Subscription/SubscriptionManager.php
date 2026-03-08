<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Subscription;

final class SubscriptionManager
{
    private readonly TopicMatcher $topicMatcher;

    /** @var array<string, array<string, Subscription>> clientId => [topicFilter => Subscription] */
    private array $clientSubscriptions = [];

    public function __construct()
    {
        $this->topicMatcher = new TopicMatcher();
    }

    public function subscribe(
        string $clientId,
        string $topicFilter,
        int $qos,
        bool $noLocal = false,
        bool $retainAsPublished = false,
        int $retainHandling = 0,
        int $subscriptionIdentifier = 0,
    ): Subscription {
        $matchFilter = self::getMatchFilter($topicFilter);

        // Remove existing subscription for this client+topic if present
        if (isset($this->clientSubscriptions[$clientId][$topicFilter])) {
            $this->topicMatcher->unsubscribe($matchFilter, $clientId);
        }

        $sub = new Subscription($clientId, $topicFilter, $qos, $noLocal, $retainAsPublished, $retainHandling, $subscriptionIdentifier);
        $this->topicMatcher->subscribe($matchFilter, $sub);
        $this->clientSubscriptions[$clientId][$topicFilter] = $sub;

        return $sub;
    }

    public function unsubscribe(string $clientId, string $topicFilter): void
    {
        $matchFilter = self::getMatchFilter($topicFilter);
        $this->topicMatcher->unsubscribe($matchFilter, $clientId);
        unset($this->clientSubscriptions[$clientId][$topicFilter]);
    }

    /**
     * @return Subscription[]
     */
    public function getMatchingSubscriptions(string $topicName): array
    {
        return $this->topicMatcher->match($topicName);
    }

    public function removeClient(string $clientId): void
    {
        $this->topicMatcher->removeClient($clientId);
        unset($this->clientSubscriptions[$clientId]);
    }

    /**
     * @return array<string, Subscription> topicFilter => Subscription
     */
    public function getClientSubscriptions(string $clientId): array
    {
        return $this->clientSubscriptions[$clientId] ?? [];
    }

    public function hasSubscription(string $clientId, string $topicFilter): bool
    {
        return isset($this->clientSubscriptions[$clientId][$topicFilter]);
    }

    /**
     * Restore subscriptions for a client (used when restoring sessions).
     *
     * @param Subscription[] $subscriptions
     */
    public function restoreClientSubscriptions(string $clientId, array $subscriptions): void
    {
        foreach ($subscriptions as $sub) {
            $this->subscribe(
                $clientId,
                $sub->topicFilter,
                $sub->qos,
                $sub->noLocal,
                $sub->retainAsPublished,
                $sub->retainHandling,
                $sub->subscriptionIdentifier,
            );
        }
    }

    /**
     * Strip $share/group/ prefix from topic filter for matching purposes.
     */
    private static function getMatchFilter(string $topicFilter): string
    {
        if (str_starts_with($topicFilter, '$share/')) {
            $parts = explode('/', $topicFilter, 3);
            if (count($parts) >= 3) {
                return $parts[2];
            }
        }

        return $topicFilter;
    }
}
