<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Subscription;

final class Subscription
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $topicFilter,
        public readonly int $qos,
        public readonly bool $noLocal = false,
        public readonly bool $retainAsPublished = false,
        public readonly int $retainHandling = 0,
        public readonly int $subscriptionIdentifier = 0,
    ) {
    }
}
