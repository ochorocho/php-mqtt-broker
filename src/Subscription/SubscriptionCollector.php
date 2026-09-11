<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Subscription;

final class SubscriptionCollector
{
    /** @var Subscription[] */
    private array $items = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function add(Subscription $sub): void
    {
        // One subscription must be collected once even when several trie branches lead
        // to it, or the subscriber receives the same message twice.
        $key = $sub->clientId . "\0" . $sub->topicFilter;

        if (isset($this->seen[$key])) {
            return;
        }

        $this->seen[$key] = true;
        $this->items[] = $sub;
    }

    /**
     * @return Subscription[]
     */
    public function getAll(): array
    {
        return $this->items;
    }
}
