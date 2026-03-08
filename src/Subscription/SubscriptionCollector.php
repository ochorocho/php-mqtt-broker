<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Subscription;

final class SubscriptionCollector
{
    /** @var Subscription[] */
    private array $items = [];

    public function add(Subscription $sub): void
    {
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
