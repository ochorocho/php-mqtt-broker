<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Session;

use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Subscription\Subscription;

final class Session
{
    /**
     * @param Subscription[] $subscriptions
     * @param PublishPacket[] $pendingMessages Messages queued while offline
     */
    public function __construct(
        public readonly string $clientId,
        public array $subscriptions = [],
        public array $pendingMessages = [],
        public int $sessionExpiryInterval = 0,
        public float $disconnectedAt = 0.0,
        public ProtocolVersion $protocolVersion = ProtocolVersion::V311,
    ) {
    }
}
